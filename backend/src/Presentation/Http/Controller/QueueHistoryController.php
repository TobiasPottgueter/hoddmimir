<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Presentation\Http\Auth\ApiRequestGuard;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ValueError;

final readonly class QueueHistoryController
{
    public function __construct(
        private \App\Application\Backup\Metrics\QueueMetricStore $metrics,
        private \App\Domain\Shared\Clock $clock,
        private PermissionAuthorizer $authorizer,
    ) {}

    #[Route('/api/v1/operations/queue/history', name: 'api_v1_operations_queue_history', methods: ['GET'])]
    public function queueHistory(Request $request): JsonResponse
    {
        return $this->read(function () use ($request): array {
            $this->guardRead($request);
            $this->assertQuery($request, ['hours', 'targetId']);
            $hours = $request->query->get('hours', '24');
            if (!in_array($hours, ['24', '168', '720'], true)) throw new InvalidArgumentException();
            $window = new \App\Application\Backup\Metrics\QueueMetricWindow((int) $hours, $this->clock->now());
            $target = $request->query->get('targetId');
            if (null !== $target) new \App\Application\Inventory\ReadModel\ReadModelIdentifier($target);
            return ['bucketSeconds' => $window->bucketSeconds, 'retentionDays' => 30, 'items' => $this->metrics->history($window, $target)];
        });
    }

    /** @param callable(): array<string, mixed> $operation */
    private function read(callable $operation): JsonResponse
    {
        try { return new JsonResponse($operation()); }
        catch (AuthenticationFailed) { return $this->error('authentication_required', Response::HTTP_UNAUTHORIZED); }
        catch (AuthorizationDenied) { return $this->error('permission_denied', Response::HTTP_FORBIDDEN); }
        catch (InvalidArgumentException|ValueError) { return $this->error('invalid_query', Response::HTTP_BAD_REQUEST); }
        catch (Throwable) { return $this->error('read_model_unavailable', Response::HTTP_SERVICE_UNAVAILABLE); }
    }
    private function guardRead(Request $request): void { $this->authorizer->require(ApiRequestGuard::session($request)->principal, Permission::InventoryRead); }
    /** @param list<string> $allowed */
    private function assertQuery(Request $request, array $allowed): void { foreach (array_keys($request->query->all()) as $key) if (!in_array($key, $allowed, true)) throw new InvalidArgumentException(); }
    private function error(string $code, int $status): JsonResponse
    {
        $error = ['code' => $code];
        if ('invalid_query' === $code) $error['message'] = 'The query is invalid.';
        if ('read_model_unavailable' === $code) $error['message'] = 'The read model is temporarily unavailable.';
        return new JsonResponse(['error' => $error], $status);
    }
}
