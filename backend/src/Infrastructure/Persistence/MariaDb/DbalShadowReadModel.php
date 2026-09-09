<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionDetail;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowGateDetail;
use App\Application\Scheduler\Shadow\ReadModel\ShadowPageQuery;
use App\Application\Scheduler\Shadow\ReadModel\ShadowReadModel;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateScope;
use RuntimeException;

final readonly class DbalShadowReadModel implements ShadowReadModel
{
    public function __construct(private Connection $connection) {}

    public function evaluations(ShadowPageQuery $query): ShadowEvaluationPage
    {
        $where = ''; $parameters = ['limit' => $query->page->limit + 1]; $types = ['limit' => ParameterType::INTEGER];
        if (null !== $query->page->cursor) {
            $where = 'WHERE (completed_at < :at OR (completed_at = :at AND id < :id))';
            $parameters['at'] = $this->databaseDate($query->page->cursor->first);
            $parameters['id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
        }
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM scheduler_evaluation_runs $where ORDER BY completed_at DESC, id DESC LIMIT :limit", $parameters, $types);
        $hasMore = count($rows) > $query->page->limit; if ($hasMore) array_pop($rows);
        $items = array_map(fn ($row) => new ShadowEvaluationSummary($this->uuid($row['id'] ?? null), $this->uuid($row['cycle_token'] ?? null), $this->integer($row['collector_fencing_token'] ?? null), $this->integer($row['evaluator_version'] ?? null), $this->integer($row['decision_count'] ?? null), $this->integer($row['gate_count'] ?? null), $this->utc($row['started_at'] ?? null), $this->utc($row['completed_at'] ?? null), $this->utc($row['persisted_at'] ?? null)), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $next = $hasMore && null !== $last ? PageCursor::resource($query->cursorContext(), $last->completedAt, $last->id) : null;
        return new ShadowEvaluationPage($query->page, $items, $next);
    }

    public function decisions(ShadowPageQuery $query): ShadowDecisionPage
    {
        $conditions = []; $parameters = ['limit' => $query->page->limit + 1]; $types = ['limit' => ParameterType::INTEGER];
        if (null !== $query->outcome) { $conditions[] = 'decision.outcome = :outcome'; $parameters['outcome'] = $query->outcome->value; }
        if (null !== $query->reason) { $conditions[] = 'decision.reason = :reason'; $parameters['reason'] = $query->reason->value; }
        foreach (['policyId' => 'policy_id', 'targetId' => 'target_id', 'guestId' => 'guest_id'] as $property => $column) {
            $value = $query->{$property};
            if (null !== $value) {
                $conditions[] = 'decision.'.$column.' = :'.$property;
                $parameters[$property] = (new ReadModelIdentifier($value))->binary();
                $types[$property] = ParameterType::BINARY;
            }
        }
        if (null !== $query->page->cursor) {
            $conditions[] = '(run.completed_at < :at OR (run.completed_at = :at AND decision.id < :id))';
            $parameters['at'] = $this->databaseDate($query->page->cursor->first);
            $parameters['id'] = (new ReadModelIdentifier($query->page->cursor->second))->binary();
            $types['id'] = ParameterType::BINARY;
        }
        $where = [] === $conditions ? '' : 'WHERE '.implode(' AND ', $conditions);
        $rows = $this->connection->fetchAllAssociative("SELECT decision.*, run.completed_at FROM scheduler_decisions decision JOIN scheduler_evaluation_runs run ON run.id = decision.evaluation_run_id $where ORDER BY run.completed_at DESC, decision.id DESC LIMIT :limit", $parameters, $types);
        $hasMore = count($rows) > $query->page->limit; if ($hasMore) array_pop($rows);
        $items = array_map(fn ($row) => $this->summary($row), $rows);
        $last = [] === $items ? null : $items[array_key_last($items)];
        $next = $hasMore && null !== $last ? PageCursor::resource($query->cursorContext(), $last->completedAt, $last->id) : null;
        return new ShadowDecisionPage($query->page, $items, $next);
    }

    public function decision(string $id): ?ShadowDecisionDetail
    {
        $binary = (new ReadModelIdentifier($id))->binary();
        $row = $this->connection->fetchAssociative('SELECT decision.*, run.completed_at FROM scheduler_decisions decision JOIN scheduler_evaluation_runs run ON run.id = decision.evaluation_run_id WHERE decision.id = :id', ['id' => $binary], ['id' => ParameterType::BINARY]);
        if (false === $row) return null;
        $gates = $this->connection->fetchAllAssociative('SELECT gate_ordinal AS position, code, passed, scope, subject_id, observed_at, detail_code FROM scheduler_decision_gates WHERE decision_id = :id ORDER BY gate_ordinal', ['id' => $binary], ['id' => ParameterType::BINARY]);
        $mapped = array_map(fn ($gate) => new ShadowGateDetail(
            $this->integer($gate['position'] ?? null),
            GateCode::from($this->text($gate['code'] ?? null)),
            1 === $this->integer($gate['passed'] ?? null),
            GateScope::from($this->text($gate['scope'] ?? null)),
            $this->uuid($gate['subject_id'] ?? null),
            null === ($gate['observed_at'] ?? null) ? null : $this->utc($gate['observed_at']),
            GateDetailCode::from($this->text($gate['detail_code'] ?? null)),
        ), $gates);
        return new ShadowDecisionDetail($this->summary($row), $mapped);
    }

    /** @param array<string, mixed> $row */
    private function summary(array $row): ShadowDecisionSummary { return new ShadowDecisionSummary($this->uuid($row['id'] ?? null), $this->uuid($row['evaluation_run_id'] ?? null), $this->uuid($row['guest_id'] ?? null), null === ($row['node_id'] ?? null) ? null : $this->uuid($row['node_id']), DecisionOutcome::from($this->text($row['outcome'] ?? null)), null === ($row['reason'] ?? null) ? null : BackupReason::from($this->text($row['reason'])), null === ($row['priority'] ?? null) ? null : $this->integer($row['priority']), $this->uuid($row['policy_id'] ?? null), $this->integer($row['policy_revision'] ?? null), $this->uuid($row['target_id'] ?? null), $this->integer($row['target_revision'] ?? null), $this->utc($row['completed_at'] ?? null)); }
    private function uuid(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid shadow identifier.'); $hex = bin2hex($value); return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20); }
    private function integer(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (!is_string($value) || '' === $value || !ctype_digit($value)) {
            throw new RuntimeException('Invalid shadow integer.');
        }
        $normalized = ltrim($value, '0');
        $normalized = '' === $normalized ? '0' : $normalized;
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)
        ) {
            throw new RuntimeException('Invalid shadow integer.');
        }

        return (int) $normalized;
    }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid shadow text.'); return $value; }
    private function utc(mixed $value): string { if (!is_string($value)) throw new RuntimeException('Invalid shadow date.'); $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Invalid shadow date.'); return $date->format('Y-m-d\TH:i:s.u\Z'); }
    private function databaseDate(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Invalid shadow cursor date.'); return $date->format('Y-m-d H:i:s.u'); }
}
