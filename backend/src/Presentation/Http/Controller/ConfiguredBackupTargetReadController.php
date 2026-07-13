<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Application\Target\ReadModel\ConfiguredBackupTargetReadModel;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/api/v1/backup-targets', name: 'api_v1_backup_targets', methods: ['GET'])]
final readonly class ConfiguredBackupTargetReadController
{
    public function __construct(private ConfiguredBackupTargetReadModel $readModel)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request);
            $query = new ConfiguredBackupTargetQuery(
                new PageRequest($this->limit($request), $this->cursor($request)),
                $this->search($request),
                $this->enabled($request),
            );
        } catch (InvalidArgumentException) {
            return $this->error('invalid_query', 'The query is invalid.', Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->readModel->targets($query)->toArray());
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
        foreach (array_keys($request->query->all()) as $key) {
            if (!in_array($key, ['limit', 'cursor', 'search', 'enabled'], true)) {
                throw new InvalidArgumentException('The query contains an unsupported parameter.');
            }
        }
    }

    private function limit(Request $request): int
    {
        $value = $request->query->all()['limit'] ?? null;
        if (null === $value) {
            return 50;
        }
        if (!is_string($value) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            throw new InvalidArgumentException('The pagination limit is invalid.');
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

    private function search(Request $request): ?string
    {
        $value = $request->query->all()['search'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || $value !== trim($value)) {
            throw new InvalidArgumentException('The target search is invalid.');
        }
        return $value;
    }

    private function enabled(Request $request): ?bool
    {
        $value = $request->query->all()['enabled'] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || !in_array($value, ['true', 'false'], true)) {
            throw new InvalidArgumentException('The target enabled filter is invalid.');
        }
        return 'true' === $value;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
