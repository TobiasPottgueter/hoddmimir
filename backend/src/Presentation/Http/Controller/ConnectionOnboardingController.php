<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Configuration\Connection\Onboarding\OnboardProxmoxConnection;
use App\Application\Configuration\Connection\Onboarding\OnboardingGuidanceProvider;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationResult;
use App\Application\Configuration\Connection\Onboarding\OnboardingMutationStatus;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerification;
use App\Application\Configuration\Connection\Onboarding\OnboardingVerificationIssue;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\OnboardingCommandFactory;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ValueError;

final readonly class ConnectionOnboardingController
{
    public function __construct(
        private OnboardingGuidanceProvider $guidance,
        private PermissionAuthorizer $authorizer,
        private OnboardingCommandFactory $commands,
        private OnboardProxmoxConnection $onboarding,
    ) {
    }

    #[Route('/api/v1/connections/onboarding/guidance/{product}', name: 'api_v1_connection_onboarding_guidance', methods: ['GET'])]
    public function guidance(Request $request, string $product): JsonResponse
    {
        try {
            if ([] !== $request->query->all()) {
                throw new InvalidArgumentException('Onboarding guidance has no query surface.');
            }
            $this->authorizer->require(ApiRequestGuard::session($request)->principal, Permission::BackupConfigurationManage);
            return new JsonResponse($this->guidance->guidance(OnboardingProduct::from($product))->toArray());
        } catch (AuthorizationDenied) {
            return new JsonResponse(['error' => ['code' => 'permission_denied']], Response::HTTP_FORBIDDEN);
        } catch (InvalidArgumentException|ValueError) {
            return new JsonResponse(['error' => ['code' => 'invalid_request']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error' => ['code' => 'onboarding_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    #[Route('/api/v1/connections/onboarding/activate', name: 'api_v1_connection_onboarding_activate', methods: ['POST'])]
    public function activate(Request $request): JsonResponse
    {
        return $this->mutation($request, OnboardingMode::Activate);
    }

    #[Route('/api/v1/connections/{id}/onboarding/rotate', name: 'api_v1_connection_onboarding_rotate', methods: ['POST'])]
    public function rotate(Request $request, string $id): JsonResponse
    {
        return $this->mutation($request, OnboardingMode::Rotate, $id);
    }

    #[Route('/api/v1/connections/{id}/onboarding/endpoints', name: 'api_v1_connection_onboarding_endpoint_add', methods: ['POST'])]
    public function addEndpoint(Request $request, string $id): JsonResponse
    {
        return $this->mutation($request, OnboardingMode::EndpointAdd, $id);
    }

    #[Route('/api/v1/connections/{id}/onboarding/endpoints/{endpointId}', name: 'api_v1_connection_onboarding_endpoint_update', methods: ['PUT'])]
    public function updateEndpoint(Request $request, string $id, string $endpointId): JsonResponse
    {
        return $this->mutation($request, OnboardingMode::EndpointUpdate, $id, $endpointId);
    }

    private function mutation(
        Request $request,
        OnboardingMode $mode,
        ?string $connectionId = null,
        ?string $endpointId = null,
    ): JsonResponse
    {
        try {
            $command = $this->commands->fromRequest($request, $mode, $connectionId, $endpointId);
            return $this->result($this->onboarding->execute($command, ApiRequestGuard::session($request)->principal));
        } catch (AuthorizationDenied) {
            return new JsonResponse(['error' => ['code' => 'permission_denied']], Response::HTTP_FORBIDDEN);
        } catch (JsonException|InvalidArgumentException|ValueError) {
            return new JsonResponse(['error' => ['code' => 'invalid_request']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error' => ['code' => 'onboarding_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function result(OnboardingMutationResult $result): JsonResponse
    {
        return match ($result->status) {
            OnboardingMutationStatus::Applied, OnboardingMutationStatus::Replayed => new JsonResponse([
                'status' => $result->status->value,
                'connectionId' => $this->uuid($result->connectionId),
                'revision' => $result->revision,
                'onboardingStatus' => 'first_automatic_scan_pending',
                'verification' => $this->verification($result->verification, true),
            ]),
            OnboardingMutationStatus::Conflict => new JsonResponse([
                'error' => ['code' => 'revision_conflict', 'currentRevision' => $result->revision],
            ], Response::HTTP_CONFLICT),
            OnboardingMutationStatus::Rejected => new JsonResponse([
                'error' => [
                    'code' => 'onboarding_verification_failed',
                    'issues' => array_map(
                        static fn (OnboardingVerificationIssue $issue): array => $issue->toArray(),
                        ($result->verification ?? throw new InvalidArgumentException('The onboarding verification is unavailable.'))->issues,
                    ),
                    'verification' => $this->verification($result->verification, false),
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY),
            OnboardingMutationStatus::Denied => new JsonResponse([
                'error' => ['code' => 'permission_denied'],
            ], Response::HTTP_FORBIDDEN),
        };
    }

    /** @return array<string, mixed> */
    private function verification(?OnboardingVerification $verification, bool $activated): array
    {
        return ($verification ?? throw new InvalidArgumentException('The onboarding verification is unavailable.'))->toArray($activated);
    }

    private function uuid(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
