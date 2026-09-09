<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicyReadModel;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class PolicyReadController
{
    public function __construct(private PolicyReadModel $readModel)
    {
    }

    #[Route('/api/v1/policies', name: 'api_v1_policies', methods: ['GET'])]
    public function policies(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request, ['limit', 'cursor', 'search', 'status']);
            $query = new PolicyListQuery(
                new PageRequest($this->limit($request), $this->cursor($request)),
                $this->optionalText($request, 'search'),
                $this->optionalText($request, 'status'),
            );
        } catch (InvalidArgumentException) {
            return $this->error('invalid_query', 'The query is invalid.', Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->readModel->policies($query)->toArray());
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    #[Route('/api/v1/policies/{id}/selection', name: 'api_v1_policy_selection', methods: ['GET'])]
    public function selection(string $id, Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request, ['limit', 'cursor']);
            $query = new PolicySelectionQuery(
                $id,
                new PageRequest($this->limit($request), $this->cursor($request)),
            );
        } catch (InvalidArgumentException) {
            return $this->error('invalid_query', 'The query is invalid.', Response::HTTP_BAD_REQUEST);
        }

        try {
            return new JsonResponse($this->readModel->selection($query)->toArray());
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /** @param list<string> $allowed */
    private function assertQueryKeys(Request $request, array $allowed): void
    {
        foreach (array_keys($request->query->all()) as $key) {
            if (!in_array($key, $allowed, true)) {
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
        if (!is_string($value)) {
            throw new InvalidArgumentException('The pagination cursor is invalid.');
        }
        return PageCursor::decode($value);
    }

    private function optionalText(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || trim($value) !== $value) {
            throw new InvalidArgumentException('The policy filter is invalid.');
        }
        return $value;
    }

    private function unavailable(): JsonResponse
    {
        return $this->error(
            'read_model_unavailable',
            'The read model is temporarily unavailable.',
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
