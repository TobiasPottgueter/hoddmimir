<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Backup\Operations\BackupOperationCommandHandler;
use App\Application\Backup\Operations\BackupOperationCommandResult;
use App\Application\Backup\Operations\BackupOperationCommandStatus;
use App\Application\Backup\Operations\BackupRequestState;
use App\Application\Backup\Operations\BackupRunState;
use App\Application\Backup\Operations\OperationsReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\BackupOperationCommandFactory;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ValueError;

final readonly class BackupOperationsController
{
    public function __construct(
        private OperationsReadModel $readModel,
        private BackupOperationCommandFactory $commands,
        private BackupOperationCommandHandler $handler,
        private PermissionAuthorizer $authorizer,
    ) {}

    #[Route('/api/v1/operations/dashboard', name: 'api_v1_operations_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse { return $this->read(function () use ($request): array { $this->guardRead($request); return $this->readModel->dashboard(ApiRequestGuard::session($request)->principal->has(Permission::AuditRead))->toArray(); }); }

    #[Route('/api/v1/operations/queue', name: 'api_v1_operations_queue', methods: ['GET'])]
    public function queue(Request $request): JsonResponse
    {
        return $this->read(function () use ($request): array {
            $this->guardRead($request);
            $this->assertQuery($request, ['limit','cursor','state']);
            $state = $request->query->get('state');
            return $this->readModel->queue($this->page($request), null === $state ? null : BackupRequestState::from($state))->toArray();
        });
    }

    #[Route('/api/v1/operations/runs', name: 'api_v1_operations_runs', methods: ['GET'])]
    public function runs(Request $request): JsonResponse
    {
        return $this->read(function () use ($request): array {
            $this->guardRead($request);
            $this->assertQuery($request, ['limit','cursor','state']);
            $state = $request->query->get('state');
            return $this->readModel->runs($this->page($request), null === $state ? null : BackupRunState::from($state))->toArray();
        });
    }

    #[Route('/api/v1/operations/runs/{id}', name: 'api_v1_operations_run', methods: ['GET'])]
    public function run(string $id, Request $request): JsonResponse
    {
        return $this->read(function () use ($id, $request): array {
            $this->guardRead($request); $this->assertQuery($request, []); $item = $this->readModel->run($id);
            if (null === $item) throw new OperationsNotFound(); return $item;
        });
    }

    #[Route('/api/v1/operations/requests/{id}/events', name: 'api_v1_operations_request_events', methods: ['GET'])]
    public function requestEvents(string $id, Request $request): JsonResponse { return $this->pageRead($request, fn (PageRequest $page): array => $this->readModel->requestEvents($id, $page)->toArray()); }
    #[Route('/api/v1/operations/runs/{id}/events', name: 'api_v1_operations_run_events', methods: ['GET'])]
    public function runEvents(string $id, Request $request): JsonResponse { return $this->pageRead($request, fn (PageRequest $page): array => $this->readModel->runEvents($id, $page)->toArray()); }
    #[Route('/api/v1/operations/runs/{id}/logs', name: 'api_v1_operations_run_logs', methods: ['GET'])]
    public function runLogs(string $id, Request $request): JsonResponse { return $this->pageRead($request, fn (PageRequest $page): array => $this->readModel->runLogs($id, $page)->toArray()); }
    #[Route('/api/v1/operations/notifications/health', name: 'api_v1_operations_notification_health', methods: ['GET'])]
    public function notifications(Request $request): JsonResponse { return $this->read(function () use ($request): array { $this->guardRead($request); $this->assertQuery($request, []); return $this->readModel->notificationHealth()->toArray(); }); }
    #[Route('/api/v1/operations/notifications', name: 'api_v1_operations_notifications', methods: ['GET'])]
    public function notificationList(Request $request): JsonResponse
    {
        return $this->read(function () use ($request): array {
            $this->guardRead($request); $this->assertQuery($request, ['limit','cursor','kind']);
            $kind = $request->query->get('kind');
            return $this->readModel->notifications($this->page($request), null === $kind ? null : $kind)->toArray();
        });
    }

    #[Route('/api/v1/operations/requests', name: 'api_v1_operations_manual_request', methods: ['POST'])]
    public function manual(Request $request): JsonResponse
    {
        try {
            $command = $this->commands->manual($request);
            return $this->command($this->handler->handle($command, ApiRequestGuard::session($request)->principal), $this->uuid($command->subjectId), Response::HTTP_CREATED);
        } catch (AuthorizationDenied) { return $this->error('permission_denied', Response::HTTP_FORBIDDEN); }
        catch (\JsonException|InvalidArgumentException) { return $this->error('invalid_request', Response::HTTP_BAD_REQUEST); }
        catch (Throwable) { return $this->error('operations_unavailable', Response::HTTP_SERVICE_UNAVAILABLE); }
    }

    #[Route('/api/v1/operations/requests/{id}/cancel', name: 'api_v1_operations_cancel_request', methods: ['POST'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        try {
            $command = $this->commands->cancel($request, $id);
            return $this->command($this->handler->handle($command, ApiRequestGuard::session($request)->principal), $id, Response::HTTP_OK);
        } catch (AuthorizationDenied) { return $this->error('permission_denied', Response::HTTP_FORBIDDEN); }
        catch (\JsonException|InvalidArgumentException) { return $this->error('invalid_request', Response::HTTP_BAD_REQUEST); }
        catch (Throwable) { return $this->error('operations_unavailable', Response::HTTP_SERVICE_UNAVAILABLE); }
    }

    /** @param callable(): array<string, mixed> $operation */
    private function read(callable $operation): JsonResponse
    {
        try { return new JsonResponse($operation()); }
        catch (AuthenticationFailed) { return $this->error('authentication_required', Response::HTTP_UNAUTHORIZED); }
        catch (AuthorizationDenied) { return $this->error('permission_denied', Response::HTTP_FORBIDDEN); }
        catch (OperationsNotFound) { return $this->error('not_found', Response::HTTP_NOT_FOUND); }
        catch (InvalidArgumentException|ValueError) { return $this->error('invalid_query', Response::HTTP_BAD_REQUEST); }
        catch (Throwable) { return $this->error('read_model_unavailable', Response::HTTP_SERVICE_UNAVAILABLE); }
    }
    /** @param callable(PageRequest): array<string, mixed> $operation */
    private function pageRead(Request $request, callable $operation): JsonResponse { return $this->read(function () use ($request, $operation): array { $this->guardRead($request); $this->assertQuery($request, ['limit','cursor']); return $operation($this->page($request)); }); }
    private function page(Request $request): PageRequest
    {
        $limit = $request->query->get('limit', '50'); $cursor = $request->query->get('cursor');
        if (1 !== preg_match('/\A[1-9][0-9]*\z/D', $limit)) throw new InvalidArgumentException();
        return new PageRequest((int) $limit, null === $cursor ? null : PageCursor::decode($cursor));
    }
    /** @param list<string> $allowed */
    private function assertQuery(Request $request, array $allowed): void { foreach (array_keys($request->query->all()) as $key) if (!in_array($key, $allowed, true)) throw new InvalidArgumentException(); }
    private function command(BackupOperationCommandResult $result, string $id, int $appliedStatus): JsonResponse
    {
        return match ($result->status) {
            BackupOperationCommandStatus::Applied => new JsonResponse(['status'=>'applied','requestId'=>$id,'revision'=>$result->revision], $appliedStatus),
            BackupOperationCommandStatus::Replayed => new JsonResponse(['status'=>'replayed','requestId'=>$id,'revision'=>$result->revision]),
            BackupOperationCommandStatus::Conflict => new JsonResponse(['error'=>['code'=>'revision_conflict','currentRevision'=>$result->revision]], Response::HTTP_CONFLICT),
            BackupOperationCommandStatus::Blocked => new JsonResponse(['error'=>['code'=>'operation_blocked','blocker'=>$result->blocker]], Response::HTTP_UNPROCESSABLE_ENTITY),
        };
    }
    private function error(string $code, int $status): JsonResponse { return new JsonResponse(['error'=>['code'=>$code]], $status); }
    private function guardRead(Request $request): void { $this->authorizer->require(ApiRequestGuard::session($request)->principal, Permission::InventoryRead); }
    private function uuid(string $binary): string { $hex = bin2hex($binary); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20); }
}

final class OperationsNotFound extends \RuntimeException {}
