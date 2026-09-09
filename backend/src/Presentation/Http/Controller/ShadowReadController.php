<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Scheduler\Shadow\ReadModel\ShadowPageQuery;
use App\Application\Scheduler\Shadow\ReadModel\ShadowReadModel;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class ShadowReadController
{
    public function __construct(private ShadowReadModel $readModel) {}

    #[Route('/api/v1/shadow/evaluations', name: 'api_v1_shadow_evaluations', methods: ['GET'])]
    public function evaluations(Request $request): JsonResponse { return $this->page($request, 'shadow-evaluations-v1', true); }

    #[Route('/api/v1/shadow/decisions', name: 'api_v1_shadow_decisions', methods: ['GET'])]
    public function decisions(Request $request): JsonResponse { return $this->page($request, 'shadow-decisions-v1', false); }

    #[Route('/api/v1/shadow/decisions/{id}', name: 'api_v1_shadow_decision', methods: ['GET'])]
    public function decision(string $id, Request $request): JsonResponse
    {
        try {
            $this->assertKeys($request);
            $identifier = new ReadModelIdentifier($id);
            $item = $this->readModel->decision($identifier->value);
        } catch (InvalidArgumentException|\ValueError) {
            return $this->error('invalid_query', 'The query is invalid.', 400);
        } catch (Throwable) {
            return $this->error('read_model_unavailable', 'The read model is temporarily unavailable.', 503);
        }
        return null === $item ? $this->error('not_found', 'The shadow decision was not found.', 404) : new JsonResponse($item->toArray());
    }

    private function page(Request $request, string $context, bool $evaluations): JsonResponse
    {
        try {
            $allowed = $evaluations ? ['limit', 'cursor'] : ['limit', 'cursor', 'outcome', 'reason', 'policyId', 'targetId', 'guestId'];
            $this->assertKeys($request, $allowed);
            $parameters = $request->query->all();
            $limit = $parameters['limit'] ?? '50';
            if (!is_string($limit) || 1 !== preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $limit)) throw new InvalidArgumentException();
            $cursorValue = $parameters['cursor'] ?? null;
            if (null !== $cursorValue && !is_string($cursorValue)) throw new InvalidArgumentException();
            $query = new ShadowPageQuery(
                new PageRequest((int) $limit, null === $cursorValue ? null : PageCursor::decode($cursorValue)),
                $context,
                $evaluations ? null : $this->optionalOutcome($parameters),
                $evaluations ? null : $this->optionalReason($parameters),
                $evaluations ? null : $this->optionalIdentifier($parameters, 'policyId'),
                $evaluations ? null : $this->optionalIdentifier($parameters, 'targetId'),
                $evaluations ? null : $this->optionalIdentifier($parameters, 'guestId'),
            );
            $page = $evaluations ? $this->readModel->evaluations($query) : $this->readModel->decisions($query);
            return new JsonResponse($page->toArray());
        } catch (InvalidArgumentException|\ValueError) {
            return $this->error('invalid_query', 'The query is invalid.', 400);
        } catch (Throwable) {
            return $this->error('read_model_unavailable', 'The read model is temporarily unavailable.', 503);
        }
    }

    /** @param list<string> $allowed */
    private function assertKeys(Request $request, array $allowed = []): void { foreach (array_keys($request->query->all()) as $key) if (!in_array($key, $allowed, true)) throw new InvalidArgumentException(); }
    /** @param array<string, mixed> $parameters */
    private function optionalIdentifier(array $parameters, string $key): ?string
    {
        $value = $parameters[$key] ?? null;
        if (null === $value) return null;
        if (!is_string($value)) throw new InvalidArgumentException();
        return (new ReadModelIdentifier($value))->value;
    }
    /** @param array<string, mixed> $parameters */
    private function optionalOutcome(array $parameters): ?DecisionOutcome
    {
        $value = $parameters['outcome'] ?? null;
        if (null === $value) return null;
        if (!is_string($value)) throw new InvalidArgumentException();
        return DecisionOutcome::from($value);
    }
    /** @param array<string, mixed> $parameters */
    private function optionalReason(array $parameters): ?BackupReason
    {
        $value = $parameters['reason'] ?? null;
        if (null === $value) return null;
        if (!is_string($value)) throw new InvalidArgumentException();
        return BackupReason::from($value);
    }
    private function error(string $code, string $message, int $status): JsonResponse { return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status); }
}
