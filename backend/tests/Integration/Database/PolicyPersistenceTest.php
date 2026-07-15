<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use App\Domain\Policy\BackupMode;
use App\Domain\Policy\BackupPolicy;
use App\Domain\Policy\Compression;
use App\Domain\Policy\PolicyActivationFailed;
use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyThresholds;
use App\Domain\Policy\RetentionPolicy;
use App\Domain\Policy\Schedule;
use App\Domain\Target\BackupTargetId;
use Doctrine\DBAL\Exception;

final class PolicyPersistenceTest extends DatabaseTestCase
{
    private const string NOW = '2026-07-12 20:00:00.000000';

    public function testPolicyConstraintsFailClosedWithoutInventingDraftDefaults(): void
    {
        $context = $this->seedContext();
        $draft = $context['policy'];
        $this->connection()->insert('backup_policies', $this->policyRow($context, $draft));

        self::assertSame(
            ['target_id' => null, 'policy_priority' => null, 'maximum_age_seconds' => null, 'schedule' => null],
            $this->connection()->fetchAssociative(
                'SELECT target_id, policy_priority, maximum_age_seconds, schedule FROM backup_policies WHERE id = :id',
                ['id' => $draft],
            ),
        );

        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policies',
            $this->policyRow($context, random_bytes(16), ['display_name' => 'Half bytes', 'bytes_written_threshold' => 1]),
        ));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policies',
            $this->policyRow($context, random_bytes(16), [
                'display_name' => 'Bad priority', 'policy_priority' => 1001,
            ]),
        ));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policies',
            $this->policyRow($context, random_bytes(16), [
                'display_name' => 'Dual retention', 'legacy_maxfiles' => 2, 'keep_last' => 2,
            ]),
        ));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policies',
            $this->policyRow($context, random_bytes(16), [
                'display_name' => 'Bad schedule', 'schedule' => 'cron',
            ]),
        ));
    }

    public function testLegacyMaxfilesDraftCanBePersistedButNeverActivatedForPveNine(): void
    {
        $context = $this->seedContext();
        $this->connection()->insert('backup_policies', $this->policyRow($context, $context['policy'], [
            'display_name' => 'Legacy draft', 'target_id' => $context['target'],
            'policy_priority' => 500, 'backup_mode' => 'snapshot', 'compression' => 'zstd',
            'maximum_age_seconds' => 3600, 'schedule' => 'collector_cycle', 'legacy_maxfiles' => 3,
        ]));
        $policy = BackupPolicy::draft(
            new PolicyId($context['policy']),
            new PolicyRevision(1),
            new BackupTargetId($context['target']),
            BackupMode::Snapshot,
            Compression::Zstd,
            RetentionPolicy::legacyMaxFiles(3),
            new PolicyPriority(500),
            new PolicyThresholds(3600, null, null),
            Schedule::CollectorCycle,
        );

        $this->expectException(PolicyActivationFailed::class);
        $policy->activate(9);
    }

    public function testAssignmentsOverridesAndShadowPolicyAreContextBoundAndNullSafeUnique(): void
    {
        $context = $this->seedContext();
        $this->connection()->insert('backup_policies', $this->policyRow($context, $context['policy']));
        $assignment = $this->assignmentRow($context, random_bytes(16), 'global');
        $this->connection()->insert('backup_policy_assignments', $assignment);
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policy_assignments',
            $this->assignmentRow($context, random_bytes(16), 'global'),
        ));

        $this->connection()->insert('backup_policy_assignments', $this->assignmentRow(
            $context,
            random_bytes(16),
            'guest',
        ));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policy_assignments',
            $this->assignmentRow($context, random_bytes(16), 'guest', [
                'subject_connection_id' => $context['connection_b'],
                'subject_cluster_id' => $context['cluster_b'],
                'guest_id' => $context['guest_b'],
            ]),
        ));

        $this->connection()->insert('backup_policy_guest_overrides', $this->overrideRow($context, random_bytes(16)));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policy_guest_overrides',
            $this->overrideRow($context, random_bytes(16)),
        ));
        $this->assertRejected(fn () => $this->connection()->insert(
            'backup_policy_guest_overrides',
            $this->overrideRow($context, random_bytes(16), [
                'connection_id' => $context['connection_b'],
                'cluster_id' => $context['cluster_b'],
                'guest_id' => $context['guest_b'],
            ]),
        ));

        $decision = random_bytes(16);
        $this->connection()->insert('scheduler_decisions', $this->decisionRow($context, $decision));
        foreach ([
            ['inventory_fresh', 'inventory'],
            ['executor_authorization_fresh', 'authorization'],
        ] as $ordinal => [$code, $scope]) {
            $this->connection()->insert('scheduler_decision_gates', [
                'decision_id' => $decision, 'gate_ordinal' => $ordinal + 1,
                'code' => $code, 'passed' => 1, 'scope' => $scope,
                'subject_id' => $context['guest'], 'observed_at' => self::NOW,
                'detail_code' => 'passed',
            ]);
        }
        $this->assertRejected(fn () => $this->connection()->update(
            'scheduler_decisions',
            ['policy_id' => random_bytes(16)],
            ['id' => $decision],
        ));
    }

    public function testRealReadModelPaginatesPoliciesAndUnifiedSelection(): void
    {
        $context = $this->seedContext();
        foreach ([[$context['policy'], 'Alpha'], [random_bytes(16), 'Zulu 50%_copy']] as [$id, $name]) {
            $this->connection()->insert('backup_policies', $this->policyRow($context, $id, [
                'display_name' => $name,
            ]));
        }
        $this->connection()->insert('backup_policy_assignments', $this->assignmentRow(
            $context,
            random_bytes(16),
            'global',
        ));
        $this->connection()->insert('backup_policy_guest_overrides', $this->overrideRow($context, random_bytes(16)));

        $model = $this->policyReadModel();
        $first = $model->policies(new PolicyListQuery(new PageRequest(1), null, 'draft'));
        self::assertSame('Alpha', $first->items[0]->displayName);
        self::assertNotNull($first->nextCursor);
        $second = $model->policies(new PolicyListQuery(new PageRequest(1, $first->nextCursor), null, 'draft'));
        self::assertSame('Zulu 50%_copy', $second->items[0]->displayName);
        self::assertCount(1, $model->policies(new PolicyListQuery(new PageRequest(10), '%_', null))->items);

        $selection = $model->selection(new PolicySelectionQuery($this->uuid($context['policy']), new PageRequest(1)));
        self::assertSame('assignment', $selection->items[0]->kind);
        self::assertNotNull($selection->nextCursor);
        $continued = $model->selection(new PolicySelectionQuery(
            $this->uuid($context['policy']),
            new PageRequest(1, $selection->nextCursor),
        ));
        self::assertSame('guest_override', $continued->items[0]->kind);
    }

    public function testReadModelSuppressesRetentionExecutionAndExposesBlockerAfterTargetDriftsToPbs(): void
    {
        $context = $this->seedContext();
        $this->connection()->insert('backup_policies', $this->policyRow($context, $context['policy'], [
            'target_id' => $context['target'],
            'display_name' => 'Drifted PBS policy',
            'status' => 'enabled',
            'policy_priority' => 500,
            'backup_mode' => 'snapshot',
            'compression' => 'zstd',
            'maximum_age_seconds' => 3600,
            'schedule' => 'collector_cycle',
            'keep_last' => 2,
            'retention_execution_enabled' => 1,
        ]));
        $this->connection()->update('pve_storages', ['storage_type' => 'pbs'], ['id' => $context['storage']]);

        $page = $this->policyReadModel()->policies(
            new PolicyListQuery(new PageRequest(10), null, 'enabled'),
        );

        self::assertCount(1, $page->items);
        self::assertFalse($page->items[0]->retentionExecutionEnabled);
        self::assertSame(
            [
                'pve_evidence_missing', 'target_disabled', 'target_evidence_missing', 'executor_evidence_missing',
                'retention_execution_forbidden_for_pbs_target',
            ],
            array_column($page->items[0]->blockers, 'value'),
        );
        self::assertFalse($page->items[0]->toArray()['retentionExecutionEnabled']);
        self::assertSame(
            [
                'pve_evidence_missing', 'target_disabled', 'target_evidence_missing', 'executor_evidence_missing',
                'retention_execution_forbidden_for_pbs_target',
            ],
            $page->items[0]->toArray()['blockers'],
        );
    }

    /** @param array<string, string> $context
     *  @param array<string, mixed> $override
     *  @return array<string, mixed>
     */
    private function policyRow(array $context, string $id, array $override = []): array
    {
        return array_replace([
            'id' => $id, 'connection_id' => $context['connection'], 'cluster_id' => $context['cluster'],
            'target_id' => null, 'display_name' => 'Draft', 'status' => 'draft', 'revision' => 1,
            'policy_priority' => null, 'backup_mode' => null, 'compression' => null,
            'maximum_age_seconds' => null, 'bytes_written_threshold' => null, 'cooldown_seconds' => null,
            'schedule' => null, 'legacy_maxfiles' => null, 'keep_all' => null, 'keep_last' => null,
            'keep_hourly' => null, 'keep_daily' => null, 'keep_weekly' => null,
            'keep_monthly' => null, 'keep_yearly' => null, 'retention_execution_enabled' => 0,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => null,
        ], $override);
    }

    /** @param array<string, string> $context
     *  @param array<string, mixed> $override
     *  @return array<string, mixed>
     */
    private function assignmentRow(array $context, string $id, string $scope, array $override = []): array
    {
        $subjects = match ($scope) {
            'global' => [],
            'guest' => [
                'subject_connection_id' => $context['connection'],
                'subject_cluster_id' => $context['cluster'],
                'guest_id' => $context['guest'],
            ],
            default => throw new \InvalidArgumentException('Unsupported test selection scope.'),
        };
        return array_replace([
            'id' => $id, 'policy_id' => $context['policy'], 'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster'], 'scope' => $scope, 'subject_connection_id' => null,
            'subject_cluster_id' => null, 'node_id' => null, 'guest_id' => null,
            'selection_value' => 'include', 'status' => 'active', 'revision' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'disabled_at' => null,
        ], $subjects, $override);
    }

    /** @param array<string, string> $context
     *  @param array<string, mixed> $override
     *  @return array<string, mixed>
     */
    private function overrideRow(array $context, string $id, array $override = []): array
    {
        return array_replace([
            'id' => $id, 'policy_id' => $context['policy'], 'connection_id' => $context['connection'],
            'cluster_id' => $context['cluster'], 'guest_id' => $context['guest'],
            'backup_mode' => 'stop', 'compression' => null, 'legacy_maxfiles' => null,
            'keep_all' => null, 'keep_last' => 2, 'keep_hourly' => null, 'keep_daily' => null,
            'keep_weekly' => null, 'keep_monthly' => null, 'keep_yearly' => null,
            'status' => 'active', 'revision' => 1, 'created_at' => self::NOW,
            'updated_at' => self::NOW, 'disabled_at' => null,
        ], $override);
    }

    /** @param array<string, string> $context
     *  @return array<string, mixed>
     */
    private function decisionRow(array $context, string $id): array
    {
        return [
            'id' => $id, 'evaluation_run_id' => $context['evaluation_run'], 'decision_ordinal' => 1,
            'connection_id' => $context['connection'], 'cluster_id' => $context['cluster'],
            'guest_id' => $context['guest'], 'node_id' => null, 'placement_revision' => null,
            'placement_observed_at' => null, 'outcome' => 'blocked', 'reason' => null, 'priority' => null,
            'policy_id' => $context['policy'], 'policy_revision' => 1,
            'policy_snapshot_hash' => str_repeat("\x01", 32), 'target_id' => null,
            'target_revision' => null, 'inventory_observed_at' => self::NOW,
            'capacity_observed_at' => null, 'write_state_observed_at' => null,
        ];
    }

    /** @return array<string, string> */
    private function seedContext(): array
    {
        $context = [
            'connection' => random_bytes(16), 'connection_b' => random_bytes(16),
            'cluster' => random_bytes(16), 'cluster_b' => random_bytes(16),
            'guest' => random_bytes(16), 'guest_b' => random_bytes(16),
            'policy' => random_bytes(16), 'evaluation_run' => random_bytes(16),
            'target' => random_bytes(16), 'storage' => random_bytes(16),
        ];
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([
                [$context['connection'], $context['cluster'], $context['guest'], 'A'],
                [$context['connection_b'], $context['cluster_b'], $context['guest_b'], 'B'],
            ] as [$connection, $cluster, $guest, $name]) {
                $run = random_bytes(16);
                $this->connection()->insert('proxmox_connections', [
                    'id' => $connection, 'display_name' => 'PVE '.$name, 'product' => 'pve', 'enabled' => 1,
                    'revision' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
                ]);
                $this->connection()->insert('pve_clusters', [
                    'id' => $cluster, 'connection_id' => $connection, 'external_name' => 'cluster-'.$name,
                    'topology' => 'clustered', 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
                $this->connection()->insert('guests', [
                    'id' => $guest, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'guest_type' => 'qemu', 'vmid' => 'A' === $name ? 100 : 200,
                    'name' => 'vm-'.$name, 'is_template' => 0, 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => self::NOW, 'last_seen_at' => self::NOW,
                ]);
            }
            $this->connection()->insert('scheduler_evaluation_runs', [
                'id' => $context['evaluation_run'], 'cycle_token' => random_bytes(16),
                'collector_fencing_token' => 1, 'evaluator_version' => 1,
                'payload_hash' => random_bytes(32), 'decision_count' => 1, 'gate_count' => 2,
                'started_at' => self::NOW, 'completed_at' => self::NOW, 'persisted_at' => self::NOW,
            ]);
            $this->connection()->insert('pve_storages', [
                'id' => $context['storage'], 'connection_id' => $context['connection'],
                'cluster_id' => $context['cluster'], 'storage_name' => 'local-backup',
                'storage_type' => 'dir', 'supports_backup' => 1, 'shared' => 0,
                'disabled' => 0, 'content_json' => '["backup"]',
                'inventory_state' => 'active', 'first_seen_run_id' => random_bytes(16),
                'last_seen_run_id' => random_bytes(16), 'first_seen_at' => self::NOW,
                'last_seen_at' => self::NOW, 'archived_at' => null,
            ]);
            $this->connection()->insert('backup_targets', [
                'id' => $context['target'], 'connection_id' => $context['connection'],
                'cluster_id' => $context['cluster'], 'storage_id' => $context['storage'],
                'display_name' => 'Target', 'status' => 'disabled', 'revision' => 1,
                'minimum_free_bytes' => null, 'created_at' => self::NOW,
                'updated_at' => self::NOW, 'disabled_at' => self::NOW,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
        return $context;
    }

    private function policyReadModel(): DbalPolicyReadModel
    {
        $evidence = new \App\Infrastructure\Persistence\MariaDb\DbalActivationEvidenceProvider($this->connection());
        return new DbalPolicyReadModel(
            $this->connection(),
            $evidence,
            new \App\Application\Configuration\Policy\PolicyActivationAssessor(),
            new \App\Tests\Fakes\FrozenClock(new \DateTimeImmutable('2026-07-12T10:00:00Z')),
            new \App\Domain\Scheduler\EvidenceFreshnessPolicy(),
        );
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('MariaDB accepted an invalid policy relation.');
        } catch (Exception) {
            self::addToAssertionCount(1);
        }
    }

    private function uuid(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'
            .substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
