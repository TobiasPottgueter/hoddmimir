<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Configuration\ConfigurationCommandResult;
use App\Application\Configuration\ConfigurationCommandStatus;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Configuration\Connection\ConnectionCommandHandler;
use App\Application\Configuration\Connection\ConnectionReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Domain\Security\Permission;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\ConnectionCommandFactory;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class ConnectionAdministrationController
{
    public function __construct(
        private ConnectionReadModel $readModel,
        private PermissionAuthorizer $authorizer,
        private ConnectionCommandFactory $commands,
        private ConnectionCommandHandler $handler,
    ) {
    }

    #[Route('/api/v1/connections', name: 'api_v1_connections', methods: ['GET'])]
    public function connections(Request $request): JsonResponse
    {
        return $this->read($request, function () use ($request): array {
            $query = $request->query->all();
            $limit = $query['limit'] ?? '50';
            $cursor = $query['cursor'] ?? null;
            if (!is_string($limit) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $limit) || (null !== $cursor && !is_string($cursor))) {
                throw new InvalidArgumentException('Invalid pagination.');
            }
            return $this->readModel->connections(new PageRequest((int) $limit, null === $cursor ? null : PageCursor::decode($cursor)));
        }, ['limit', 'cursor']);
    }

    #[Route('/api/v1/connections/{id}', name: 'api_v1_connection', methods: ['GET'])]
    public function connection(Request $request, string $id): JsonResponse
    {
        return $this->read($request, function () use ($id): array {
            return $this->readModel->connection($id) ?? throw new ConnectionNotFound();
        });
    }

    #[Route('/api/v1/connections/{id}', name: 'api_v1_connection_update', methods: ['PUT'])]
    public function update(Request $request, string $id): JsonResponse { return $this->command($request, ConfigurationCommandType::ConnectionUpdate, $id); }
    #[Route('/api/v1/connections/{id}/disable', name: 'api_v1_connection_disable', methods: ['POST'])]
    public function disable(Request $request, string $id): JsonResponse { return $this->command($request, ConfigurationCommandType::ConnectionDisable, $id); }
    #[Route('/api/v1/connections/{id}/endpoints/{endpointId}/disable', name: 'api_v1_endpoint_disable', methods: ['POST'])]
    public function disableEndpoint(Request $request, string $id, string $endpointId): JsonResponse { return $this->command($request, ConfigurationCommandType::EndpointDisable, $id, $endpointId); }

    /**
     * @param callable(): array<string, mixed> $operation
     * @param list<string> $allowedQuery
     */
    private function read(Request $request, callable $operation, array $allowedQuery = []): JsonResponse
    {
        try {
            foreach (array_keys($request->query->all()) as $key) {
                if (!in_array($key, $allowedQuery, true)) throw new InvalidArgumentException('Query parameters are not supported.');
            }
            $this->authorizer->require(ApiRequestGuard::session($request)->principal, Permission::BackupConfigurationManage);
            return new JsonResponse($operation());
        } catch (AuthorizationDenied) {
            return new JsonResponse(['error'=>['code'=>'permission_denied']], Response::HTTP_FORBIDDEN);
        } catch (ConnectionNotFound) {
            return new JsonResponse(['error'=>['code'=>'not_found']], Response::HTTP_NOT_FOUND);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['error'=>['code'=>'invalid_query']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error'=>['code'=>'connection_read_model_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function command(Request $request, ConfigurationCommandType $type, ?string $id = null, ?string $endpointId = null): JsonResponse
    {
        try {
            $command = $this->commands->fromRequest($request, $type, $id, $endpointId);
            return $this->result($this->handler->handle($command, ApiRequestGuard::session($request)->principal));
        } catch (AuthorizationDenied) {
            return new JsonResponse(['error'=>['code'=>'permission_denied']], Response::HTTP_FORBIDDEN);
        } catch (JsonException|InvalidArgumentException|\ValueError) {
            return new JsonResponse(['error'=>['code'=>'invalid_request']], Response::HTTP_BAD_REQUEST);
        } catch (Throwable) {
            return new JsonResponse(['error'=>['code'=>'connection_administration_unavailable']], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function result(ConfigurationCommandResult $result): JsonResponse
    {
        return match ($result->status) {
            ConfigurationCommandStatus::Applied, ConfigurationCommandStatus::Replayed => new JsonResponse(['status'=>$result->status->value,'revision'=>$result->revision]),
            ConfigurationCommandStatus::Conflict => new JsonResponse(['error'=>['code'=>'revision_conflict','currentRevision'=>$result->revision]], Response::HTTP_CONFLICT),
            ConfigurationCommandStatus::Blocked => new JsonResponse(['error'=>['code'=>'connection_change_blocked','blockers'=>$result->blockers]], Response::HTTP_UNPROCESSABLE_ENTITY),
            ConfigurationCommandStatus::Denied => new JsonResponse(['error'=>['code'=>'permission_denied']], Response::HTTP_FORBIDDEN),
        };
    }
}

final class ConnectionNotFound extends \RuntimeException {}
