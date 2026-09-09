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

final readonly class PbsTaskReadController
{
    public function __construct(
        private \App\Application\Monitoring\PbsTaskReadModel $tasks,
        private PermissionAuthorizer $authorizer,
    ) {}

    #[Route('/api/v1/operations/pbs-tasks', name: 'api_v1_operations_pbs_tasks', methods: ['GET'])]
    public function tasks(Request $request): JsonResponse
    {
        return $this->read(function () use ($request): array {
            $this->guardRead($request);
            $this->assertQuery($request, ['connectionId', 'offset']);
            $offset = $request->query->get('offset', '0');
            if (1 !== preg_match('/\A[0-9]{1,7}\z/D', $offset)) throw new InvalidArgumentException();
            $connection = $request->query->get('connectionId');
            return $this->tasks->tasks(null === $connection ? null : new \App\Application\Inventory\ReadModel\ReadModelIdentifier($connection), (int) $offset);
        });
    }

    #[Route('/api/v1/operations/pbs-tasks/{id}', name: 'api_v1_operations_pbs_task', methods: ['GET'])]
    public function detail(Request $request, string $id): JsonResponse
    {
        return $this->read(function () use ($request, $id): array {
            $this->guardRead($request);
            $this->assertQuery($request, []);
            return $this->tasks->detail(new \App\Application\Inventory\ReadModel\ReadModelIdentifier($id))
                ?? throw new \OutOfBoundsException();
        });
    }

    /** @param callable(): array<string, mixed> $operation */
    private function read(callable $operation): JsonResponse
    {
        try { return new JsonResponse($operation()); }
        catch (AuthenticationFailed) { return $this->error('authentication_required', Response::HTTP_UNAUTHORIZED); }
        catch (AuthorizationDenied) { return $this->error('permission_denied', Response::HTTP_FORBIDDEN); }
        catch (InvalidArgumentException|ValueError) { return $this->error('invalid_query', Response::HTTP_BAD_REQUEST); }
        catch (\OutOfBoundsException) { return $this->error('pbs_task_not_found', Response::HTTP_NOT_FOUND); }
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
