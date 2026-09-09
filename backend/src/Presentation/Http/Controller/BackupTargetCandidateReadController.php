<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Application\Target\ReadModel\BackupTargetCandidateReadModel;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/backup-target-candidates', name: 'api_v1_backup_target_candidates', methods: ['GET'])]
final readonly class BackupTargetCandidateReadController
{
    public function __construct(private BackupTargetCandidateReadModel $readModel)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request);
            $query = new BackupTargetCandidateQuery(
                new PageRequest(
                    $this->integer($request, 'limit', 50),
                    $this->cursor($request),
                ),
                $this->identifier($request, 'connectionId'),
                $this->identifier($request, 'clusterId'),
            );
        } catch (InvalidArgumentException) {
            return $this->invalidQuery();
        }

        try {
            return new JsonResponse($this->readModel->candidates($query)->toArray());
        } catch (Throwable) {
            return new JsonResponse([
                'error' => [
                    'code' => 'read_model_unavailable',
                    'message' => 'The read model is temporarily unavailable.',
                ],
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function assertQueryKeys(Request $request): void
    {
        foreach (array_keys($request->query->all()) as $key) {
            if (!in_array($key, ['limit', 'cursor', 'connectionId', 'clusterId'], true)) {
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
        if (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
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

    private function invalidQuery(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'invalid_query',
                'message' => 'The query is invalid.',
            ],
        ], Response::HTTP_BAD_REQUEST);
    }
}
