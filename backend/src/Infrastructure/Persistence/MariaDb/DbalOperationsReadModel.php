<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Backup\Operations\BackupRequestState;
use App\Application\Backup\Operations\BackupRunState;
use App\Application\Backup\Operations\OperationsPage;
use App\Application\Backup\Operations\OperationsReadModel;
use App\Application\Backup\Operations\OperationsCollectorSchedule;
use App\Application\Backup\Operations\OperationsDashboard;
use App\Application\Backup\Operations\OperationsNotificationHealth;
use App\Application\Backup\Operations\OperationsLastSuccessfulRun;
use App\Application\Backup\Operations\OperationsWorkerHealth;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use App\Domain\Shared\Clock;

final readonly class DbalOperationsReadModel implements OperationsReadModel
{
    public function __construct(private Connection $connection, private Clock $clock, private int $evidenceFreshnessSeconds = 300)
    {
        if ($evidenceFreshnessSeconds < 1 || $evidenceFreshnessSeconds > 86_400) throw new \InvalidArgumentException('Invalid operations evidence freshness.');
    }

    public function dashboard(bool $includeAudit): OperationsDashboard
    {
        $requests = $this->connection->fetchAllKeyValue('SELECT state, COUNT(*) FROM backup_requests GROUP BY state');
        $requestReasons = $this->connection->fetchAllKeyValue('SELECT reason, COUNT(*) FROM backup_requests GROUP BY reason');
        $runs = $this->connection->fetchAllKeyValue('SELECT state, COUNT(*) FROM backup_runs GROUP BY state');
        $oldest = $this->connection->fetchOne("SELECT MIN(scheduled_at) FROM backup_requests WHERE state IN ('pending','retry_wait')");
        $problems = $this->connection->fetchOne('SELECT COUNT(*) FROM backup_problem_states');
        $lastSuccessfulRun = $this->connection->fetchAssociative(<<<'SQL'
SELECT run.id, guest.name AS guest_name, guest.vmid, node.node_name, target.display_name AS target_name, run.finished_at
FROM backup_runs run
JOIN backup_requests request ON request.id=run.request_id
JOIN guests guest ON guest.id=request.guest_id
JOIN pve_nodes node ON node.id=request.node_id
JOIN backup_targets target ON target.id=request.target_id
WHERE run.state='succeeded' AND run.finished_at IS NOT NULL
ORDER BY run.finished_at DESC,run.id DESC LIMIT 1
SQL);

        $schedule = $this->connection->fetchAssociative("SELECT next_scan_at,last_cycle_started_at,last_cycle_finished_at FROM collector_schedule WHERE schedule_name='inventory'");
        $workers = [];
        foreach (['collector','backup'] as $kind) {
            $worker = $this->connection->fetchAssociative('SELECT status,heartbeat_at,expires_at,next_action_at,build_version,current_activity FROM worker_heartbeats WHERE worker_kind=:kind ORDER BY heartbeat_at DESC,worker_instance_id DESC LIMIT 1', ['kind'=>$kind]);
            $workers[$kind] = false === $worker ? null : new OperationsWorkerHealth(
                $this->text($worker['status'] ?? null), $this->utc($worker['heartbeat_at'] ?? null),
                $this->utc($worker['expires_at'] ?? null), $this->databaseDateTime($worker['expires_at'] ?? null) > $this->clock->now(),
                $this->nullableUtc($worker['next_action_at'] ?? null), $this->text($worker['build_version'] ?? null),
                $this->nullableText($worker['current_activity'] ?? null),
            );
        }
        $resources = [
            'systems'=>$this->countSql('SELECT COUNT(*) FROM proxmox_connections WHERE enabled=1'),
            'nodes'=>$this->countSql("SELECT COUNT(*) FROM pve_nodes WHERE inventory_state='active'"),
            'guests'=>$this->countSql("SELECT COUNT(*) FROM guests WHERE inventory_state='active' AND COALESCE(is_template,0)=0"),
            'targets'=>$this->countSql("SELECT COUNT(*) FROM backup_targets WHERE status='enabled'"),
            'policies'=>$this->countSql("SELECT COUNT(*) FROM backup_policies WHERE status='enabled'"),
        ];
        $latestEvaluation = $this->connection->fetchOne('SELECT id FROM scheduler_evaluation_runs ORDER BY completed_at DESC,id DESC LIMIT 1');
        $shadowBlockers = !is_string($latestEvaluation) ? 0 : $this->integer($this->connection->fetchOne("SELECT COUNT(*) FROM scheduler_decisions WHERE evaluation_run_id=:id AND outcome='blocked'", ['id'=>$latestEvaluation], ['id'=>ParameterType::BINARY]));
        $cutoff = $this->clock->now()->modify('-'.$this->evidenceFreshnessSeconds.' seconds')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $staleEvidence = $this->integer($this->connection->fetchOne(<<<'SQL'
SELECT
 (SELECT COUNT(*) FROM pve_nodes WHERE inventory_state='active' AND last_seen_at < :cutoff) +
 (SELECT COUNT(*) FROM guests WHERE inventory_state='active' AND last_seen_at < :cutoff) +
 (SELECT COUNT(*) FROM pve_storages WHERE inventory_state='active' AND last_seen_at < :cutoff) +
 (SELECT COUNT(*) FROM guest_placements WHERE observed_at < :cutoff) +
 (SELECT COUNT(*) FROM pve_node_storage_state WHERE observed_at < :cutoff) +
 (SELECT COUNT(*) FROM pbs_datastore_capacity_state WHERE observed_at < :cutoff) +
 (SELECT COUNT(*) FROM current_executor_permission_evidence WHERE observed_at < :cutoff) +
 (SELECT COUNT(*) FROM proxmox_capability_snapshots WHERE last_observed_at < :cutoff)
SQL, ['cutoff'=>$cutoff]));
        $lastSuccessful = $this->connection->fetchOne("SELECT MAX(applied_at) FROM inventory_sync_runs WHERE status='succeeded' AND authoritative=1 AND applied_at IS NOT NULL");
        $audit = [];
        if ($includeAudit) {
            $auditRows = $this->connection->fetchAllAssociative('SELECT id,occurred_at,event_type,outcome,subject_type,reason_code FROM audit_events ORDER BY occurred_at DESC,id DESC LIMIT 5');
            $audit = array_map(fn (array $row): array => ['id'=>$this->uuid($row['id'] ?? null),'occurredAt'=>$this->utc($row['occurred_at'] ?? null),'eventType'=>$this->text($row['event_type'] ?? null),'outcome'=>$this->text($row['outcome'] ?? null),'subjectType'=>$this->nullableText($row['subject_type'] ?? null),'reasonCode'=>$this->nullableText($row['reason_code'] ?? null)], $auditRows);
        }

        /** @var array{collector:?OperationsWorkerHealth,backup:?OperationsWorkerHealth} $workers */
        /** @var array{systems:int,nodes:int,guests:int,targets:int,policies:int} $resources */
        $reasons = $this->counts($requestReasons, ['manual', 'never_backed_up', 'max_age', 'bytes_written']);
        /** @var array{manual:int,never_backed_up:int,max_age:int,bytes_written:int} $reasons */
        return new OperationsDashboard(
            $workers,
            false === $schedule ? null : new OperationsCollectorSchedule(
                $this->utc($schedule['next_scan_at'] ?? null),
                $this->nullableUtc($schedule['last_cycle_started_at'] ?? null),
                $this->nullableUtc($schedule['last_cycle_finished_at'] ?? null),
                is_string($lastSuccessful) ? $this->utc($lastSuccessful) : null,
            ),
            $resources,
            $this->counts($requests, array_column(BackupRequestState::cases(), 'value')),
            $reasons,
            $this->counts($runs, array_column(BackupRunState::cases(), 'value')),
            is_string($oldest) ? $this->utc($oldest) : null,
            false === $lastSuccessfulRun ? null : new OperationsLastSuccessfulRun(
                $this->uuid($lastSuccessfulRun['id'] ?? null),
                $this->nullableText($lastSuccessfulRun['guest_name'] ?? null),
                $this->integer($lastSuccessfulRun['vmid'] ?? null),
                $this->text($lastSuccessfulRun['node_name'] ?? null),
                $this->text($lastSuccessfulRun['target_name'] ?? null),
                $this->utc($lastSuccessfulRun['finished_at'] ?? null),
            ),
            $staleEvidence,
            $shadowBlockers,
            $this->integer($problems),
            $this->notificationHealth(),
            $audit,
            $includeAudit,
        );
    }

    public function queue(PageRequest $page, ?BackupRequestState $state): OperationsPage
    {
        $context = PageCursor::context('backup-queue-v1', null === $state ? 'all' : $state->value);
        $where = []; $parameters = ['limit' => $page->limit + 1]; $types = ['limit' => ParameterType::INTEGER];
        if (null !== $state) { $where[] = 'request.state = :state'; $parameters['state'] = $state->value; }
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            [$priority, $scheduled] = $this->queueCursor($page->cursor->first);
            $where[] = '(request.priority < :cursor_priority OR (request.priority = :cursor_priority AND (request.scheduled_at > :cursor_at OR (request.scheduled_at = :cursor_at AND request.id > :cursor_id))))';
            $parameters['cursor_priority'] = $priority;
            $types['cursor_priority'] = ParameterType::INTEGER;
            $parameters['cursor_at'] = $this->databaseDate($scheduled);
            $parameters['cursor_id'] = (new ReadModelIdentifier($page->cursor->second))->binary();
            $types['cursor_id'] = ParameterType::BINARY;
        }
        $predicate = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);
        $rows = $this->connection->fetchAllAssociative(<<<SQL
        SELECT request.*, guest.vmid, guest.guest_type, guest.name AS guest_name,
               node.node_name, policy.display_name AS policy_name, target.display_name AS target_name
        FROM backup_requests request
        JOIN guests guest ON guest.connection_id=request.connection_id AND guest.cluster_id=request.cluster_id AND guest.id=request.guest_id
        JOIN pve_nodes node ON node.connection_id=request.connection_id AND node.cluster_id=request.cluster_id AND node.id=request.node_id
        JOIN backup_policies policy ON policy.connection_id=request.connection_id AND policy.cluster_id=request.cluster_id AND policy.id=request.policy_id
        JOIN backup_targets target ON target.connection_id=request.connection_id AND target.cluster_id=request.cluster_id AND target.id=request.target_id
        $predicate
        ORDER BY request.priority DESC, request.scheduled_at ASC, request.id ASC LIMIT :limit
        SQL, $parameters, $types);
        /** @var list<array<string, mixed>> $rows */
        $hasMore = count($rows) > $page->limit; if ($hasMore) array_pop($rows);
        /** @var list<array<string, mixed>> $items */
        $items = array_map(fn (array $row): array => $this->request($row), $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource(
            $context,
            str_pad((string) $this->integer($last['priority'] ?? null), 5, '0', STR_PAD_LEFT).'|'.$this->utc($last['scheduled_at'] ?? null),
            $this->uuid($last['id'] ?? null),
        ) : null;

        return new OperationsPage($page, $items, $next);
    }

    public function runs(PageRequest $page, ?BackupRunState $state): OperationsPage
    {
        $context = PageCursor::context('backup-runs-v1', null === $state ? 'all' : $state->value);
        $where = []; $parameters = ['limit' => $page->limit + 1]; $types = ['limit' => ParameterType::INTEGER];
        if (null !== $state) { $where[] = 'run.state = :state'; $parameters['state'] = $state->value; }
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $where[] = '(run.started_at < :cursor_at OR (run.started_at = :cursor_at AND run.id < :cursor_id))';
            $parameters['cursor_at'] = $this->databaseDate($page->cursor->first);
            $parameters['cursor_id'] = (new ReadModelIdentifier($page->cursor->second))->binary();
            $types['cursor_id'] = ParameterType::BINARY;
        }
        $predicate = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);
        $rows = $this->connection->fetchAllAssociative(<<<SQL
SELECT run.*, request.guest_id, guest.vmid, guest.guest_type, guest.name AS guest_name,
       node.node_name, policy.display_name AS policy_name, target.display_name AS target_name
FROM backup_runs run
JOIN backup_requests request ON request.id = run.request_id
JOIN guests guest ON guest.id = request.guest_id
JOIN pve_nodes node ON node.id = request.node_id
JOIN backup_policies policy ON policy.id = request.policy_id
JOIN backup_targets target ON target.id = request.target_id
$predicate
ORDER BY run.started_at DESC, run.id DESC LIMIT :limit
SQL, $parameters, $types);
        /** @var list<array<string, mixed>> $rows */

        return $this->page($page, $rows, $context, 'started_at', fn (array $row): array => $this->runSummary($this->associative($row)));
    }

    public function run(string $id): ?array
    {
        $binary = (new ReadModelIdentifier($id))->binary();
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT run.*, request.guest_id, request.policy_id, request.target_id, request.reason, request.priority,
       request.revision AS request_revision, request.cancel_requested_at AS request_cancel_requested_at,
       guest.vmid, guest.guest_type, guest.name AS guest_name, node.node_name,
       policy.display_name AS policy_name, target.display_name AS target_name
FROM backup_runs run
JOIN backup_requests request ON request.id = run.request_id
JOIN guests guest ON guest.id = request.guest_id
JOIN pve_nodes node ON node.id = request.node_id
JOIN backup_policies policy ON policy.id = request.policy_id
JOIN backup_targets target ON target.id = request.target_id
WHERE run.id = :id
SQL, ['id' => $binary], ['id' => ParameterType::BINARY]);
        if (false === $row) return null;
        /** @var array<string, mixed> $row */
        $result = $this->runSummary($row);
        $result['requestRevision'] = $this->integer($row['request_revision'] ?? null);
        $result['requestCancelRequestedAt'] = $this->nullableUtc($row['request_cancel_requested_at'] ?? null);
        $result['reason'] = $this->text($row['reason'] ?? null);
        $result['priority'] = $this->integer($row['priority'] ?? null);
        $result['exitStatus'] = $this->nullableText($row['exit_status'] ?? null);
        $result['statusFailureCode'] = $this->nullableText($row['status_failure_code'] ?? null);
        $result['stopAttemptClaimedAt'] = $this->nullableUtc($row['stop_attempt_claimed_at'] ?? null);
        $result['stopAttemptStatus'] = $this->nullableText($row['stop_attempt_status'] ?? null);
        $result['stopAttemptResolvedAt'] = $this->nullableUtc($row['stop_attempt_resolved_at'] ?? null);
        $result['stopFailureCode'] = $this->nullableText($row['stop_failure_code'] ?? null);
        $result['recoveryOutcome'] = $this->nullableText($row['recovery_outcome'] ?? null);
        $result['nextLogOffset'] = $this->integer($row['next_log_offset'] ?? null);

        return $result;
    }

    public function requestEvents(string $requestId, PageRequest $page): OperationsPage
    {
        return $this->events('backup_request_events', 'request_id', $requestId, $page, 'backup-request-events-v1');
    }

    public function runEvents(string $runId, PageRequest $page): OperationsPage
    {
        return $this->events('backup_run_events', 'run_id', $runId, $page, 'backup-run-events-v1');
    }

    public function runLogs(string $runId, PageRequest $page): OperationsPage
    {
        $binary = (new ReadModelIdentifier($runId))->binary();
        $context = PageCursor::context('backup-run-logs-v1', $runId);
        $parameters = ['id' => $binary, 'limit' => $page->limit + 1];
        $types = ['id' => ParameterType::BINARY, 'limit' => ParameterType::INTEGER];
        $cursor = '';
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $cursor = 'AND line_no > :line';
            $parameters['line'] = $this->cursorInteger($page->cursor->first);
            $types['line'] = ParameterType::INTEGER;
        }
        $rows = $this->connection->fetchAllAssociative("SELECT run_id AS id, line_no, observed_at, content FROM backup_run_log_entries WHERE run_id = :id $cursor ORDER BY line_no ASC LIMIT :limit", $parameters, $types);
        $hasMore = count($rows) > $page->limit; if ($hasMore) array_pop($rows);
        $items = array_map(fn (array $row): array => [
            'lineNo' => $this->integer($row['line_no'] ?? null),
            'observedAt' => $this->utc($row['observed_at'] ?? null),
            'content' => $this->text($row['content'] ?? null),
        ], $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource($context, (string) $this->integer($last['line_no'] ?? null), $runId) : null;

        return new OperationsPage($page, $items, $next);
    }

    public function notificationHealth(): OperationsNotificationHealth
    {
        $counts = $this->connection->fetchAllKeyValue('SELECT state, COUNT(*) FROM backup_notification_outbox GROUP BY state');
        $oldest = $this->connection->fetchOne("SELECT MIN(created_at) FROM backup_notification_outbox WHERE state <> 'sent'");
        $lastError = $this->connection->fetchAssociative("SELECT last_error_code, available_at FROM backup_notification_outbox WHERE last_error_code IS NOT NULL ORDER BY available_at DESC, id DESC LIMIT 1");

        $byState = $this->counts($counts, ['pending', 'claimed', 'sent']);
        /** @var array{pending:int,claimed:int,sent:int} $byState */
        return new OperationsNotificationHealth(
            $byState,
            is_string($oldest) ? $this->utc($oldest) : null,
            false === $lastError ? null : $this->nullableText($lastError['last_error_code'] ?? null),
            false === $lastError ? null : $this->utc($lastError['available_at'] ?? null),
        );
    }

    public function notifications(PageRequest $page, ?string $kind): OperationsPage
    {
        if (null !== $kind && !in_array($kind, ['failure','attention_required','recovery'], true)) throw new \InvalidArgumentException('Invalid notification kind.');
        $context = PageCursor::context('backup-notifications-v1', $kind ?? 'all');
        $where = []; $parameters = ['limit'=>$page->limit + 1]; $types = ['limit'=>ParameterType::INTEGER];
        if (null !== $kind) { $where[] = 'notification_kind=:kind'; $parameters['kind'] = $kind; }
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $where[] = '(created_at < :at OR (created_at=:at AND id < :id))';
            $parameters['at'] = $this->databaseDate($page->cursor->first);
            $parameters['id'] = (new ReadModelIdentifier($page->cursor->second))->binary(); $types['id'] = ParameterType::BINARY;
        }
        $predicate = [] === $where ? '' : 'WHERE '.implode(' AND ', $where);
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM backup_notification_outbox $predicate ORDER BY created_at DESC,id DESC LIMIT :limit", $parameters, $types);
        $hasMore = count($rows) > $page->limit; if ($hasMore) array_pop($rows);
        $items = array_map(function (array $row): array {
            $raw = $this->text($row['payload_json'] ?? null);
            $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) throw new RuntimeException('Invalid notification payload.');
            return [
                'id'=>$this->uuid($row['id'] ?? null),'kind'=>$this->text($row['notification_kind'] ?? null),
                'state'=>$this->text($row['state'] ?? null),
                'attempt'=>null === ($row['attempt'] ?? null) ? null : $this->integer($row['attempt']),
                'checkNumber'=>$this->integer($row['check_number'] ?? null),
                'deliveryAttempts'=>$this->integer($row['delivery_attempts'] ?? null),
                'guestName'=>$this->text($payload['guestName'] ?? null),'guestType'=>$this->text($payload['guestType'] ?? null),
                'vmid'=>$this->integer($payload['vmid'] ?? null),'node'=>$this->text($payload['node'] ?? null),
                'targetLabel'=>$this->text($payload['targetLabel'] ?? null),'problemCode'=>$this->text($payload['problemCode'] ?? null),
                'detailCode'=>$this->nullableText($payload['detailCode'] ?? null),'occurredAt'=>$this->text($payload['occurredAt'] ?? null),
                'nextRetryAt'=>$this->nullableText($payload['nextRetryAt'] ?? null),'consecutiveFailures'=>$this->integer($payload['consecutiveFailures'] ?? null),
                'lastErrorCode'=>$this->nullableText($row['last_error_code'] ?? null),'createdAt'=>$this->utc($row['created_at'] ?? null),
                'sentAt'=>$this->nullableUtc($row['sent_at'] ?? null),
            ];
        }, $rows);
        /** @var list<array<string, mixed>> $items */
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource($context, $this->utc($last['created_at'] ?? null), $this->uuid($last['id'] ?? null)) : null;
        return new OperationsPage($page, $items, $next);
    }

    private function events(string $table, string $foreignKey, string $subjectId, PageRequest $page, string $namespace): OperationsPage
    {
        $binary = (new ReadModelIdentifier($subjectId))->binary();
        $context = PageCursor::context($namespace, $subjectId);
        $parameters = ['id' => $binary, 'limit' => $page->limit + 1];
        $types = ['id' => ParameterType::BINARY, 'limit' => ParameterType::INTEGER];
        $cursor = '';
        if (null !== $page->cursor) {
            $page->cursor->assertContext(PageCursorKind::Resource, $context);
            $cursor = 'AND sequence_no > :sequence';
            $parameters['sequence'] = $this->cursorInteger($page->cursor->first);
            $types['sequence'] = ParameterType::INTEGER;
        }
        $rows = $this->connection->fetchAllAssociative("SELECT * FROM $table WHERE $foreignKey = :id $cursor ORDER BY sequence_no ASC LIMIT :limit", $parameters, $types);
        $hasMore = count($rows) > $page->limit; if ($hasMore) array_pop($rows);
        $items = array_map(fn (array $row): array => [
            'id' => $this->uuid($row['id'] ?? null), 'sequence' => $this->integer($row['sequence_no'] ?? null),
            'type' => $this->text($row['event_type'] ?? null), 'state' => $this->text($row['state'] ?? null),
            'occurredAt' => $this->utc($row['occurred_at'] ?? null), 'detailCode' => $this->nullableText($row['detail_code'] ?? null),
        ], $rows);
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource($context, (string) $this->integer($last['sequence_no'] ?? null), $this->uuid($last['id'] ?? null)) : null;

        return new OperationsPage($page, $items, $next);
    }

    /** @param list<array<string, mixed>> $rows @param callable(array<string, mixed>): array<string, mixed> $map */
    private function page(PageRequest $page, array $rows, string $context, string $dateColumn, callable $map): OperationsPage
    {
        $hasMore = count($rows) > $page->limit; if ($hasMore) array_pop($rows);
        $items = array_map($map, $rows);
        /** @var list<array<string, mixed>> $items */
        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $next = $hasMore && null !== $last ? PageCursor::resource($context, $this->utc($last[$dateColumn] ?? null), $this->uuid($last['id'] ?? null)) : null;
        return new OperationsPage($page, $items, $next);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function request(array $row): array
    {
        return [
            'id' => $this->uuid($row['id'] ?? null), 'rootRequestId' => $this->uuid($row['root_request_id'] ?? null),
            'runId' => null === ($row['run_id'] ?? null) ? null : $this->uuid($row['run_id']),
            'state' => BackupRequestState::from($this->text($row['state'] ?? null))->value,
            'origin' => $this->text($row['origin'] ?? null), 'reason' => $this->text($row['reason'] ?? null),
            'priority' => $this->integer($row['priority'] ?? null), 'attempt' => $this->integer($row['attempt'] ?? null),
            'revision' => $this->integer($row['revision'] ?? null), 'guestId' => $this->uuid($row['guest_id'] ?? null),
            'policyId' => $this->uuid($row['policy_id'] ?? null), 'targetId' => $this->uuid($row['target_id'] ?? null),
            'guestName' => $this->nullableText($row['guest_name'] ?? null), 'guestType' => $this->text($row['guest_type'] ?? null),
            'vmid' => $this->integer($row['vmid'] ?? null), 'nodeName' => $this->text($row['node_name'] ?? null),
            'policyName' => $this->text($row['policy_name'] ?? null), 'targetName' => $this->text($row['target_name'] ?? null),
            'scheduledAt' => $this->utc($row['scheduled_at'] ?? null), 'availableAt' => $this->utc($row['available_at'] ?? null),
            'createdAt' => $this->utc($row['created_at'] ?? null), 'updatedAt' => $this->utc($row['updated_at'] ?? null),
            'cancelRequestedAt' => $this->nullableUtc($row['cancel_requested_at'] ?? null),
            'terminalCode' => $this->nullableText($row['terminal_code'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function runSummary(array $row): array
    {
        return [
            'id' => $this->uuid($row['id'] ?? null), 'requestId' => $this->uuid($row['request_id'] ?? null),
            'rootRequestId' => $this->uuid($row['root_request_id'] ?? null), 'guestId' => $this->uuid($row['guest_id'] ?? null),
            'state' => BackupRunState::from($this->text($row['state'] ?? null))->value,
            'attempt' => $this->integer($row['attempt'] ?? null), 'revision' => $this->integer($row['revision'] ?? null),
            'submissionProvenance' => $this->text($row['submission_provenance'] ?? null),
            'upid' => $this->nullableText($row['upid'] ?? null), 'guestName' => $this->nullableText($row['guest_name'] ?? null),
            'guestType' => $this->text($row['guest_type'] ?? null), 'vmid' => $this->integer($row['vmid'] ?? null),
            'nodeName' => $this->text($row['node_name'] ?? null), 'policyName' => $this->text($row['policy_name'] ?? null),
            'targetName' => $this->text($row['target_name'] ?? null), 'startedAt' => $this->utc($row['started_at'] ?? null),
            'finishedAt' => $this->nullableUtc($row['finished_at'] ?? null),
        ];
    }

    /** @param array<mixed, mixed> $rows
     * @param list<string> $known
     * @return array<string, int>
     */
    private function counts(array $rows, array $known): array { $result = array_fill_keys($known, 0); foreach ($rows as $key => $value) if (is_string($key) && array_key_exists($key, $result)) $result[$key] = $this->integer($value); return $result; }
    private function uuid(mixed $value): string { if (!is_string($value) || 16 !== strlen($value)) throw new RuntimeException('Invalid operations identifier.'); $hex = bin2hex($value); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20); }
    private function integer(mixed $value): int { if (is_int($value) && $value >= 0) return $value; if (!is_string($value) || !ctype_digit($value)) throw new RuntimeException('Invalid operations integer.'); return (int) $value; }
    private function cursorInteger(string $value): int { if (!ctype_digit($value)) throw new RuntimeException('Invalid operations cursor.'); return (int) $value; }
    private function text(mixed $value): string { if (!is_string($value) || '' === $value) throw new RuntimeException('Invalid operations text.'); return $value; }
    private function nullableText(mixed $value): ?string { return null === $value ? null : $this->text($value); }
    private function nullableUtc(mixed $value): ?string { return null === $value ? null : $this->utc($value); }
    private function utc(mixed $value): string { if (!is_string($value)) throw new RuntimeException('Invalid operations date.'); $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Invalid operations date.'); return $date->format('Y-m-d\TH:i:s.u\Z'); }
    private function databaseDate(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC')); if (false === $date) throw new RuntimeException('Invalid operations cursor date.'); return $date->format('Y-m-d H:i:s.u'); }
    private function databaseDateTime(mixed $value): DateTimeImmutable { if (!is_string($value)) throw new RuntimeException('Invalid operations date.'); $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u',$value,new DateTimeZone('UTC')); if (false===$date) throw new RuntimeException('Invalid operations date.'); return $date; }
    private function countSql(string $sql): int { return $this->integer($this->connection->fetchOne($sql)); }
    /** @param array<mixed, mixed> $row
     * @return array<string, mixed>
     */
    private function associative(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) { if (!is_string($key)) throw new RuntimeException('Invalid operations row.'); $result[$key] = $value; }
        return $result;
    }
    /** @return array{int, string} */
    private function queueCursor(string $value): array
    {
        if (1 !== preg_match('/\A([0-9]{5})\|(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z)\z/D', $value, $match)) throw new RuntimeException('Invalid queue cursor.');
        return [(int) $match[1], $match[2]];
    }
}
