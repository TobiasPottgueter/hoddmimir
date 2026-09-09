<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Inventory\ReadModel\CollectorReadModel;
use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\InventoryReadModel;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use ValueError;

#[Route('/api/v1', name: 'api_v1_')]
final readonly class InventoryReadController
{
    public function __construct(
        private InventoryReadModel $inventory,
        private CollectorReadModel $collector,
    ) {}

    #[Route('/inventory/overview', name: 'inventory_overview', methods: ['GET'])]
    public function overview(): JsonResponse
    {
        return new JsonResponse($this->inventory->overview()->toArray());
    }

    #[Route('/inventory/resources', name: 'inventory_resources', methods: ['GET'])]
    public function resources(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request, [
                'kind', 'limit', 'cursor', 'connectionId', 'parentId', 'inventoryState', 'guestType',
            ]);
            $kindValue = $this->requiredString($request, 'kind');
            $stateValue = $this->optionalString($request, 'inventoryState') ?? InventoryState::Active->value;
            $query = new InventoryResourceQuery(
                InventoryResourceKind::from($kindValue),
                $this->page($request),
                $this->identifier($request, 'connectionId'),
                $this->identifier($request, 'parentId'),
                InventoryState::from($stateValue),
                $this->optionalString($request, 'guestType'),
            );
        } catch (InvalidArgumentException|ValueError) {
            return $this->invalidQuery();
        }

        return new JsonResponse($this->inventory->resources($query)->toArray());
    }

    #[Route('/collector/status', name: 'collector_status', methods: ['GET'])]
    public function collectorStatus(): JsonResponse
    {
        return new JsonResponse($this->collector->status()->toArray());
    }

    #[Route('/collector/runs', name: 'collector_runs', methods: ['GET'])]
    public function collectorRuns(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request, ['limit', 'cursor']);
            $page = $this->page($request);
            $page->cursor?->assertContext(PageCursorKind::CollectorRun, PageCursor::collectorRunsContext());
        } catch (InvalidArgumentException) {
            return $this->invalidQuery();
        }

        return new JsonResponse($this->collector->runs($page)->toArray());
    }

    #[Route('/collector/scopes', name: 'collector_scopes', methods: ['GET'])]
    public function collectorScopes(Request $request): JsonResponse
    {
        try {
            $this->assertQueryKeys($request, ['runId', 'limit', 'cursor']);
            $runId = $this->requiredString($request, 'runId');
            $query = new CollectorScopeQuery(new ReadModelIdentifier($runId), $this->page($request));
        } catch (InvalidArgumentException) {
            return $this->invalidQuery();
        }

        return new JsonResponse($this->collector->scopes($query)->toArray());
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

    private function page(Request $request): PageRequest
    {
        return new PageRequest(
            $this->integer($request, 'limit', 50),
            $this->cursor($request),
        );
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

    private function requiredString(Request $request, string $key): string
    {
        $value = $this->optionalString($request, $key);
        if (null === $value) {
            throw new InvalidArgumentException('A required query parameter is missing.');
        }
        return $value;
    }

    private function optionalString(Request $request, string $key): ?string
    {
        $value = $request->query->all()[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || '' === $value || strlen($value) > 255) {
            throw new InvalidArgumentException('A query parameter is invalid.');
        }
        return $value;
    }

    private function identifier(Request $request, string $key): ?ReadModelIdentifier
    {
        $value = $this->optionalString($request, $key);
        return null === $value ? null : new ReadModelIdentifier($value);
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
