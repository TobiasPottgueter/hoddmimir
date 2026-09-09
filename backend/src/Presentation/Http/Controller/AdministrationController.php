<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Administration\ReadModel\AdministrationReadModel;
use App\Application\Administration\ReadModel\AuditListQuery;
use App\Application\Administration\ReadModel\UserListQuery;
use App\Application\Administration\SecurityCommandHandler;
use App\Application\Administration\SecurityCommandResult;
use App\Application\Administration\SecurityCommandStatus;
use App\Application\Administration\SecurityCommandType;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\SecurityCommandRequestFactory;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;
use ValueError;

final readonly class AdministrationController
{
    public function __construct(
        private AdministrationReadModel $readModel,
        private PermissionAuthorizer $authorizer,
        private SecurityCommandRequestFactory $commands,
        private SecurityCommandHandler $handler,
    ) {
    }

    #[Route('/api/v1/admin/users', name: 'api_v1_admin_users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        return $this->read($request, Permission::SecurityManage, function () use ($request): array {
            $this->queryKeys($request, ['limit', 'cursor', 'search', 'enabled']);
            return $this->readModel->users(new UserListQuery($this->page($request), $this->optionalText($request, 'search'), $this->optionalBool($request, 'enabled')))->toArray();
        });
    }

    #[Route('/api/v1/admin/roles', name: 'api_v1_admin_roles', methods: ['GET'])]
    public function roles(Request $request): JsonResponse
    {
        return $this->read($request, Permission::SecurityManage, function () use ($request): array {
            $this->queryKeys($request, ['limit', 'cursor']);
            return $this->readModel->roles($this->page($request))->toArray();
        });
    }

    #[Route('/api/v1/admin/audit-events', name: 'api_v1_admin_audit_events', methods: ['GET'])]
    public function audit(Request $request): JsonResponse
    {
        return $this->read($request, Permission::AuditRead, function () use ($request): array {
            $this->queryKeys($request, ['limit', 'cursor', 'actorUserId', 'eventType', 'outcome']);
            $actor = $this->optionalText($request, 'actorUserId');
            $event = $this->optionalText($request, 'eventType');
            $outcome = $this->optionalText($request, 'outcome');
            return $this->readModel->audit(new AuditListQuery($this->page($request), $actor, null === $event ? null : AuditEventType::from($event), null === $outcome ? null : AuditOutcome::from($outcome)))->toArray();
        });
    }

    #[Route('/api/v1/admin/audit-events/{id}', name: 'api_v1_admin_audit_event', methods: ['GET'])]
    public function auditEvent(Request $request, string $id): JsonResponse
    {
        return $this->read($request, Permission::AuditRead, function () use ($id): array {
            new ReadModelIdentifier($id);
            $event = $this->readModel->auditEvent($id);
            if (null === $event) {
                throw new AdministrationNotFound();
            }
            return $event->toArray();
        });
    }

    #[Route('/api/v1/admin/users', name: 'api_v1_admin_user_create', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse { return $this->command($request, SecurityCommandType::UserCreate); }
    #[Route('/api/v1/admin/users/{id}', name: 'api_v1_admin_user_update', methods: ['PUT'])]
    public function updateUser(Request $request, string $id): JsonResponse { return $this->command($request, SecurityCommandType::UserUpdate, $id); }
    #[Route('/api/v1/admin/users/{id}/disable', name: 'api_v1_admin_user_disable', methods: ['POST'])]
    public function disableUser(Request $request, string $id): JsonResponse { return $this->command($request, SecurityCommandType::UserDisable, $id); }
    #[Route('/api/v1/admin/users/{id}/roles', name: 'api_v1_admin_user_roles', methods: ['PUT'])]
    public function replaceRoles(Request $request, string $id): JsonResponse { return $this->command($request, SecurityCommandType::UserRolesReplace, $id); }

    /** @param callable(): array<string, mixed> $operation */
    private function read(Request $request, Permission $permission, callable $operation): JsonResponse
    {
        try {
            $this->authorizer->require(ApiRequestGuard::session($request)->principal, $permission);
            return new JsonResponse($operation());
        } catch (AuthorizationDenied) {
            return new JsonResponse(['error' => ['code' => 'permission_denied']], Response::HTTP_FORBIDDEN);
        } catch (AdministrationNotFound) {
            return new JsonResponse(['error' => ['code' => 'not_found']], Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException|ValueError) {
            return new JsonResponse(['error' => ['code' => 'invalid_query']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error' => ['code' => 'read_model_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function command(Request $request, SecurityCommandType $type, ?string $id = null): JsonResponse
    {
        try {
            return $this->commandResponse($this->handler->handle($this->commands->fromRequest($request, $type, $id), ApiRequestGuard::session($request)->principal));
        } catch (InvalidArgumentException|ValueError) {
            return new JsonResponse(['error' => ['code' => 'invalid_request']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error' => ['code' => 'security_administration_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function commandResponse(SecurityCommandResult $result): JsonResponse
    {
        return match ($result->status) {
            SecurityCommandStatus::Applied, SecurityCommandStatus::Replayed => new JsonResponse(['status' => $result->status->value, 'revision' => $result->revision]),
            SecurityCommandStatus::Conflict => new JsonResponse(['error' => ['code' => 'revision_conflict', 'currentRevision' => $result->revision]], Response::HTTP_CONFLICT),
            SecurityCommandStatus::Blocked => new JsonResponse(['error' => ['code' => 'security_invariant_blocked', 'blockers' => [$result->blocker]]], Response::HTTP_UNPROCESSABLE_ENTITY),
            SecurityCommandStatus::Denied => new JsonResponse(['error' => ['code' => 'permission_denied']], Response::HTTP_FORBIDDEN),
        };
    }

    private function page(Request $request): PageRequest
    {
        $all = $request->query->all();
        $limit = $all['limit'] ?? '50';
        $cursor = $all['cursor'] ?? null;
        if (!is_string($limit) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $limit) || (null !== $cursor && !is_string($cursor))) {
            throw new InvalidArgumentException('The pagination is invalid.');
        }
        return new PageRequest((int) $limit, null === $cursor ? null : PageCursor::decode($cursor));
    }

    /** @param list<string> $allowed */
    private function queryKeys(Request $request, array $allowed): void
    {
        foreach (array_keys($request->query->all()) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The query is invalid.');
            }
        }
    }

    private function optionalText(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;
        if (null !== $value && (!is_string($value) || '' === $value || trim($value) !== $value)) {
            throw new InvalidArgumentException('The query text is invalid.');
        }
        return is_string($value) ? $value : null;
    }

    private function optionalBool(Request $request, string $key): ?bool
    {
        $value = $request->query->all()[$key] ?? null;
        if (null === $value) return null;
        if ('true' === $value) return true;
        if ('false' === $value) return false;
        throw new InvalidArgumentException('The query boolean is invalid.');
    }
}

final class AdministrationNotFound extends \RuntimeException
{
}
