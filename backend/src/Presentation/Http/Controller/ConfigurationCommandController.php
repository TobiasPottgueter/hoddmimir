<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Policy\PolicyCommandHandler;
use App\Application\Configuration\Selection\SelectionCommandHandler;
use App\Application\Configuration\Target\TargetCommandHandler;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\ConfigurationCommandFactory;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class ConfigurationCommandController
{
    public function __construct(private ConfigurationCommandFactory $commands, private TargetCommandHandler $targets,
        private PolicyCommandHandler $policies, private SelectionCommandHandler $selection)
    {
    }

    #[Route('/api/v1/backup-targets', name: 'api_v1_backup_target_create', methods: ['POST'])]
    public function createTarget(Request $request): JsonResponse { return $this->target($request, ConfigurationCommandType::TargetCreate); }
    #[Route('/api/v1/backup-targets/{id}', name: 'api_v1_backup_target_update', methods: ['PUT'])]
    public function updateTarget(Request $request, string $id): JsonResponse { return $this->target($request, ConfigurationCommandType::TargetUpdate, $id); }
    #[Route('/api/v1/backup-targets/{id}/enable', name: 'api_v1_backup_target_enable', methods: ['POST'])]
    public function enableTarget(Request $request, string $id): JsonResponse { return $this->target($request, ConfigurationCommandType::TargetEnable, $id); }
    #[Route('/api/v1/backup-targets/{id}/disable', name: 'api_v1_backup_target_disable', methods: ['POST'])]
    public function disableTarget(Request $request, string $id): JsonResponse { return $this->target($request, ConfigurationCommandType::TargetDisable, $id); }
    #[Route('/api/v1/policies', name: 'api_v1_policy_create', methods: ['POST'])]
    public function createPolicy(Request $request): JsonResponse { return $this->policy($request, ConfigurationCommandType::PolicyCreate); }
    #[Route('/api/v1/policies/{id}', name: 'api_v1_policy_update', methods: ['PUT'])]
    public function updatePolicy(Request $request, string $id): JsonResponse { return $this->policy($request, ConfigurationCommandType::PolicyUpdate, $id); }
    #[Route('/api/v1/policies/{id}/enable', name: 'api_v1_policy_enable', methods: ['POST'])]
    public function enablePolicy(Request $request, string $id): JsonResponse { return $this->policy($request, ConfigurationCommandType::PolicyEnable, $id); }
    #[Route('/api/v1/policies/{id}/disable', name: 'api_v1_policy_disable', methods: ['POST'])]
    public function disablePolicy(Request $request, string $id): JsonResponse { return $this->policy($request, ConfigurationCommandType::PolicyDisable, $id); }
    #[Route('/api/v1/policies/{id}/selection', name: 'api_v1_policy_selection_upsert', methods: ['PUT'])]
    public function upsertSelection(Request $request, string $id): JsonResponse { return $this->selection($request, ConfigurationCommandType::SelectionUpsert, $id); }
    #[Route('/api/v1/policies/{id}/selection/disable', name: 'api_v1_policy_selection_disable', methods: ['POST'])]
    public function disableSelection(Request $request, string $id): JsonResponse { return $this->selection($request, ConfigurationCommandType::SelectionDisable, $id); }
    #[Route('/api/v1/policies/{id}/guest-overrides', name: 'api_v1_policy_guest_override_upsert', methods: ['PUT'])]
    public function upsertOverrides(Request $request, string $id): JsonResponse { return $this->selection($request, ConfigurationCommandType::GuestOverrideUpsert, $id); }
    #[Route('/api/v1/policies/{id}/guest-overrides/disable', name: 'api_v1_policy_guest_override_disable', methods: ['POST'])]
    public function disableOverrides(Request $request, string $id): JsonResponse { return $this->selection($request, ConfigurationCommandType::GuestOverrideDisable, $id); }

    private function target(Request $request, ConfigurationCommandType $type, ?string $id = null): JsonResponse
    { return $this->run($request, fn () => $this->targets->handle($this->commands->fromRequest($request, $type, $id), ApiRequestGuard::session($request)->principal)); }
    private function policy(Request $request, ConfigurationCommandType $type, ?string $id = null): JsonResponse
    { return $this->run($request, fn () => $this->policies->handle($this->commands->fromRequest($request, $type, $id), ApiRequestGuard::session($request)->principal)); }
    private function selection(Request $request, ConfigurationCommandType $type, string $id): JsonResponse
    { return $this->run($request, fn () => $this->selection->handle($this->commands->fromRequest($request, $type, $id), ApiRequestGuard::session($request)->principal)); }

    /** @param callable(): ConfigurationCommandResult $operation */
    private function run(Request $request, callable $operation): JsonResponse
    {
        try { return $this->response($operation()); }
        catch (AuthorizationDenied) { return new JsonResponse(['error'=>['code'=>'permission_denied']], Response::HTTP_FORBIDDEN); }
        catch (JsonException|\InvalidArgumentException) { return new JsonResponse(['error'=>['code'=>'invalid_request']], Response::HTTP_BAD_REQUEST); }
        catch (Throwable) { return new JsonResponse(['error'=>['code'=>'configuration_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE); }
    }

    private function response(ConfigurationCommandResult $result): JsonResponse
    {
        return match ($result->status) {
            ConfigurationCommandStatus::Applied, ConfigurationCommandStatus::Replayed => new JsonResponse(['status'=>$result->status->value,'revision'=>$result->revision]),
            ConfigurationCommandStatus::Conflict => new JsonResponse(['error'=>['code'=>'revision_conflict','currentRevision'=>$result->revision]], Response::HTTP_CONFLICT),
            ConfigurationCommandStatus::Blocked => new JsonResponse(['error'=>['code'=>'activation_blocked','blockers'=>$result->blockers]], Response::HTTP_UNPROCESSABLE_ENTITY),
            ConfigurationCommandStatus::Denied => new JsonResponse(['error'=>['code'=>'permission_denied']], Response::HTTP_FORBIDDEN),
        };
    }
}
