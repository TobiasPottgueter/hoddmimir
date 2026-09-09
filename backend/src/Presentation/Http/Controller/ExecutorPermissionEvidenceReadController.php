<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceQuery;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/executor-permission-evidence', name: 'api_v1_executor_permission_evidence', methods: ['GET'])]
final readonly class ExecutorPermissionEvidenceReadController
{
    private const array FILTERS = ['connectionId', 'clusterId', 'targetId', 'nodeId', 'guestId'];

    public function __construct(private ExecutorPermissionEvidenceReadModel $readModel)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request);
            $query = new ExecutorPermissionEvidenceQuery(
                new PageRequest($this->integer($request, 'limit', 50), $this->cursor($request)),
                ...array_map(fn (string $key): ?ReadModelIdentifier => $this->identifier($request, $key), self::FILTERS),
            );
        } catch (InvalidArgumentException) {
            return $this->error('invalid_query', 'The query is invalid.', Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->readModel->evidence($query)->toArray());
        } catch (Throwable) {
            return $this->error(
                'read_model_unavailable',
                'The read model is temporarily unavailable.',
                Response::HTTP_SERVICE_UNAVAILABLE,
            );
        }
    }

    private function assertQueryKeys(Request $request): void
    {
        $allowed = ['limit', 'cursor', ...self::FILTERS];
        foreach (array_keys($request->query->all()) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The query contains an unsupported parameter.');
            }
        }
    }

    private function integer(Request $request, string $key, int $default): int
    {
        $value = $request->query->all()[$key] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!is_string($value) || 1 !== preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            throw new InvalidArgumentException('A pagination parameter is invalid.');
        }

        return (int) $value;
    }

    private function cursor(Request $request): ?PageCursor
    {
        $value = $request->query->all()['cursor'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || strlen($value) > 2048) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }

        return PageCursor::decode($value);
    }

    private function identifier(Request $request, string $key): ?ReadModelIdentifier
    {
        $value = $request->query->all()[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || strlen($value) > 255) {
            throw new InvalidArgumentException('A query parameter is invalid.');
        }

        return new ReadModelIdentifier($value);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
