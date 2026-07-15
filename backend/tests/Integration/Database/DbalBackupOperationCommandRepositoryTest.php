<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Backup\Operations\BackupOperationCommand;
use App\Application\Backup\Operations\BackupOperationCommandStatus;
use App\Application\Backup\Operations\BackupOperationCommandType;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Domain\Policy\PolicyResolver;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserId;
use App\Infrastructure\Persistence\MariaDb\DbalBackupOperationCommandRepository;
use App\Infrastructure\Persistence\MariaDb\MariaDbQaFixtureSeeder;
use App\Infrastructure\Security\SystemSecurityIdentifierGenerator;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Throwable;

final class DbalBackupOperationCommandRepositoryTest extends DatabaseTestCase
{
    private const string USER = 'operation-user01';
    private const string POLICY = 'operation-policy';
    private const string REQUEST = 'operation-reques';
    private const string GUEST = 'operation-guest1';

    public function testAppliedCommandsReplayAndAuditTheirRevisions(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $repository = new DbalBackupOperationCommandRepository(
            $this->connection(),
            new SystemSecurityIdentifierGenerator(),
            $clock,
            new PolicyResolver(),
        );
        $sessionId = $this->seedWebSession(self::USER);
        $principal = new AuthenticatedPrincipal(
            new UserId(self::USER),
            new NormalizedUsername('qa-admin'),
            [Permission::BackupOperationsManage],
            $sessionId,
        );
        $request = self::uuid('c0000000-0000-4000-8000-000000000002');
        $manual = new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            $request,
            self::uuid('80000000-0000-4000-8000-000000000001'),
            self::uuid('50000000-0000-4000-8000-000000000101'),
            1,
            'manual-apply',
            'operation-apply1',
        );

        self::assertSame(BackupOperationCommandStatus::Applied, $repository->execute($manual, $principal)->status);
        self::assertSame(BackupOperationCommandStatus::Replayed, $repository->execute($manual, $principal)->status);
        self::assertSame('1', self::scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM backup_requests WHERE id = :id',
            ['id' => $request],
        )));
        self::assertSame('1', self::scalarString($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM audit_events WHERE actor_user_id = :actor AND event_type = 'manual_backup_requested'",
            ['actor' => self::USER],
        )));

        $duplicate = new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            self::uuid('c0000000-0000-4000-8000-000000000003'),
            self::uuid('80000000-0000-4000-8000-000000000001'),
            self::uuid('50000000-0000-4000-8000-000000000101'),
            1,
            'manual-active-duplicate',
            'operation-apply2',
        );
        $duplicateResult = $repository->execute($duplicate, $principal);
        self::assertSame(BackupOperationCommandStatus::Blocked, $duplicateResult->status);
        self::assertSame('active_request_exists', $duplicateResult->blocker);
        self::assertSame('1', self::scalarString($this->connection()->fetchOne(
            "SELECT COUNT(*) FROM backup_requests WHERE guest_id = :guest AND state IN ('pending','retry_wait','leased','starting','running','reconcile_required')",
            ['guest' => self::uuid('50000000-0000-4000-8000-000000000101')],
        )));

        $cancel = new BackupOperationCommand(
            BackupOperationCommandType::CancelRequest,
            $request,
            str_repeat("\0", 16),
            str_repeat("\0", 16),
            1,
            'cancel-apply',
            'operation-cancel',
        );
        $cancelled = $repository->execute($cancel, $principal);
        self::assertSame(BackupOperationCommandStatus::Applied, $cancelled->status);
        self::assertSame(2, $cancelled->revision);
        self::assertSame('cancelled', $this->connection()->fetchOne(
            'SELECT state FROM backup_requests WHERE id = :id',
            ['id' => $request],
        ));
        self::assertSame('2', self::scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE actor_user_id = :actor',
            ['actor' => self::USER],
        )));
        self::assertSame('2', self::scalarString($this->connection()->fetchOne(
            'SELECT COUNT(*) FROM audit_events WHERE actor_user_id = :actor AND actor_session_id = :session',
            ['actor' => self::USER, 'session' => $sessionId],
        )));
    }

    public function testManualPolicySnapshotUsesStorageTypeToSuppressPbsDeletionApproval(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $repository = new DbalBackupOperationCommandRepository(
            $this->connection(),
            new SystemSecurityIdentifierGenerator(),
            $clock,
            new PolicyResolver(),
        );
        $principal = new AuthenticatedPrincipal(
            new UserId(self::USER),
            new NormalizedUsername('qa-admin'),
            [Permission::BackupOperationsManage],
        );
        $this->connection()->update('backup_policies', [
            'retention_execution_enabled' => 1,
        ], ['id' => self::uuid('80000000-0000-4000-8000-000000000001')]);
        $this->connection()->update('pve_storages', [
            'storage_type' => 'pbs',
        ], ['id' => self::uuid('60000000-0000-4000-8000-000000000001')]);

        $pbsRequest = self::uuid('c3000000-0000-4000-8000-000000000001');
        $result = $repository->execute(new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            $pbsRequest,
            self::uuid('80000000-0000-4000-8000-000000000001'),
            self::uuid('50000000-0000-4000-8000-000000000101'),
            1,
            'pbs-retention-manual',
            'operation-pbs001',
        ), $principal);
        self::assertSame(BackupOperationCommandStatus::Applied, $result->status);
        $pbsSnapshotJson = $this->connection()->fetchOne(
            'SELECT resolved_policy_json FROM backup_requests WHERE id = :id',
            ['id' => $pbsRequest],
        );
        self::assertIsString($pbsSnapshotJson);
        $pbsSnapshot = json_decode($pbsSnapshotJson, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($pbsSnapshot);
        self::assertNotNull($pbsSnapshot['desiredRetention']);
        self::assertNull($pbsSnapshot['approvedDeletionRetention']);
    }

    public function testConcurrentPolicyUpdateMakesTheStaleManualRequestConflict(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the policy-revision race proof.');
        }

        $this->seedMinimalPolicy();
        $parameters = $this->connection()->getParams();
        $this->connection()->commit();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        [$parent, $child] = $sockets;
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);

        if (0 === $pid) {
            fclose($parent);
            if ('1' !== fread($child, 1)) exit(2);
            $connection = DriverManager::getConnection($parameters);
            try {
                $repository = new DbalBackupOperationCommandRepository(
                    $connection,
                    new SystemSecurityIdentifierGenerator(),
                    new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z')),
                    new PolicyResolver(),
                );
                $result = $repository->execute(
                    new BackupOperationCommand(
                        BackupOperationCommandType::ManualRequest,
                        self::REQUEST,
                        self::POLICY,
                        self::GUEST,
                        1,
                        'policy-race',
                        'operation-race01',
                    ),
                    new AuthenticatedPrincipal(
                        new UserId(self::USER),
                        new NormalizedUsername('operation-user'),
                        [Permission::BackupOperationsManage],
                    ),
                );
                fwrite($child, $result->status->value.':'.($result->revision ?? 0));
            } catch (Throwable $exception) {
                fwrite($child, $exception::class.':'.$exception->getMessage());
            } finally {
                $connection->close();
                fclose($child);
            }
            pcntl_exec('/bin/true');
            exit(3);
        }

        fclose($child);
        stream_set_timeout($parent, 15);
        $updater = DriverManager::getConnection($parameters);
        try {
            $updater->beginTransaction();
            self::assertSame('1', self::scalarString($updater->fetchOne(
                'SELECT revision FROM backup_policies WHERE id = :id FOR UPDATE',
                ['id' => self::POLICY],
            )));
            self::assertSame(1, fwrite($parent, '1'));
            self::assertSame(1, $updater->executeStatement(
                'UPDATE backup_policies SET revision = 2, updated_at = :now WHERE id = :id AND revision = 1',
                ['id' => self::POLICY, 'now' => '2026-07-13 00:00:01.000000'],
            ));
            $updater->commit();

            $result = stream_get_contents($parent);
            self::assertSame('conflict:2', $result);
            self::assertSame('0', self::scalarString($this->connection()->fetchOne(
                'SELECT COUNT(*) FROM backup_requests WHERE id = :id',
                ['id' => self::REQUEST],
            )));
            self::assertSame(BackupOperationCommandStatus::Conflict->value, $this->connection()->fetchOne(
                'SELECT result_status FROM backup_operation_commands WHERE actor_user_id = :actor AND idempotency_key = :key',
                ['actor' => self::USER, 'key' => 'policy-race'],
            ));
        } finally {
            if ($updater->isTransactionActive()) $updater->rollBack();
            $updater->close();
            fclose($parent);
            $status = null;
            pcntl_waitpid($pid, $status);
            self::assertIsInt($status);
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
            $this->cleanupCommittedFixture();
        }
    }

    public function testBlockedManualEvidenceAndCancelOutcomesReplayDeterministically(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-07-13T00:00:00Z'));
        $this->seedQaFixture($clock);
        $repository = new DbalBackupOperationCommandRepository($this->connection(), new SystemSecurityIdentifierGenerator(), $clock, new PolicyResolver());
        $principal = new AuthenticatedPrincipal(new UserId(self::USER), new NormalizedUsername('qa-admin'), [Permission::BackupOperationsManage]);

        $missing = $this->manualCommand('c1000000-0000-4000-8000-000000000001', 'missing-policy', policy: '81000000-0000-4000-8000-000000000001');
        self::assertSame(BackupOperationCommandStatus::Blocked, $repository->execute($missing, $principal)->status);
        self::assertSame(BackupOperationCommandStatus::Blocked, $repository->execute($missing, $principal)->status);
        $sameKeyDifferentPayload = $this->manualCommand('c1000000-0000-4000-8000-000000000002', 'missing-policy', policy: '82000000-0000-4000-8000-000000000001');
        self::assertSame(BackupOperationCommandStatus::Conflict, $repository->execute($sameKeyDifferentPayload, $principal)->status);

        $policy = self::uuid('80000000-0000-4000-8000-000000000001');
        $target = self::uuid('70000000-0000-4000-8000-000000000001');
        $guest = self::uuid('50000000-0000-4000-8000-000000000101');
        $cases = [
            ['policy_not_enabled', fn () => $this->connection()->update('backup_policies', ['status'=>'disabled','disabled_at'=>'2026-07-13 00:00:00.000000'], ['id'=>$policy]), fn () => $this->connection()->update('backup_policies', ['status'=>'enabled','disabled_at'=>null], ['id'=>$policy])],
            ['target_not_enabled', fn () => $this->connection()->update('backup_targets', ['status'=>'disabled','disabled_at'=>'2026-07-13 00:00:00.000000'], ['id'=>$target]), fn () => $this->connection()->update('backup_targets', ['status'=>'enabled','disabled_at'=>null], ['id'=>$target])],
            ['guest_not_eligible', fn () => $this->connection()->update('guests', ['is_template'=>1], ['id'=>$guest]), fn () => $this->connection()->update('guests', ['is_template'=>0], ['id'=>$guest])],
            ['placement_missing', fn () => $this->connection()->delete('guest_placements', ['guest_id'=>$guest]), fn () => $this->restoreQemuPlacement()],
            ['expected_size_missing', fn () => $this->connection()->update('guests', ['provisioned_size_bytes'=>null], ['id'=>$guest]), fn () => $this->connection()->update('guests', ['provisioned_size_bytes'=>1073741824], ['id'=>$guest])],
            ['pve_evidence_missing', fn () => $this->connection()->delete('proxmox_capability_snapshots', ['connection_id'=>self::uuid('10000000-0000-4000-8000-000000000001')]), fn () => $this->restoreCapability()],
        ];
        foreach ($cases as $index => [$blocker, $breakEvidence, $restoreEvidence]) {
            $breakEvidence();
            $result = $repository->execute($this->manualCommand(sprintf('c2000000-0000-4000-8000-%012d', $index + 1), 'block-'.$index), $principal);
            self::assertSame(BackupOperationCommandStatus::Blocked, $result->status);
            self::assertSame($blocker, $result->blocker);
            $restoreEvidence();
        }

        $terminal = new BackupOperationCommand(
            BackupOperationCommandType::CancelRequest,
            self::uuid('c0000000-0000-4000-8000-000000000001'), str_repeat("\0",16), str_repeat("\0",16),
            3, 'cancel-terminal', 'operation-term01',
        );
        self::assertSame('request_terminal', $repository->execute($terminal, $principal)->blocker);
        $missingCancel = new BackupOperationCommand(
            BackupOperationCommandType::CancelRequest,
            self::uuid('c9000000-0000-4000-8000-000000000001'), str_repeat("\0",16), str_repeat("\0",16),
            1, 'cancel-missing', 'operation-miss01',
        );
        self::assertSame('request_missing', $repository->execute($missingCancel, $principal)->blocker);
        $staleCancel = new BackupOperationCommand(
            BackupOperationCommandType::CancelRequest,
            self::uuid('c0000000-0000-4000-8000-000000000001'), str_repeat("\0",16), str_repeat("\0",16),
            2, 'cancel-stale', 'operation-stale1',
        );
        self::assertSame(BackupOperationCommandStatus::Conflict, $repository->execute($staleCancel, $principal)->status);
    }

    private function seedMinimalPolicy(): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $now = '2026-07-13 00:00:00.000000';
            $this->connection()->insert('users', [
                'id' => self::USER, 'username' => 'operation-user', 'display_name' => 'Operation User',
                'password_hash' => '$argon2id$fixture', 'enabled' => 1, 'revision' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection()->insert('backup_policies', [
                'id' => self::POLICY, 'connection_id' => 'operation-connec', 'cluster_id' => 'operation-cluste',
                'target_id' => 'operation-target', 'display_name' => 'Operation Policy', 'status' => 'enabled',
                'revision' => 1, 'policy_priority' => 300, 'backup_mode' => 'snapshot', 'compression' => 'zstd',
                'maximum_age_seconds' => 86400, 'schedule' => 'collector_cycle', 'keep_last' => 7,
                'retention_execution_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->connection()->insert('guests', [
                'id' => self::GUEST, 'connection_id' => 'operation-connec', 'cluster_id' => 'operation-cluste',
                'guest_type' => 'qemu', 'vmid' => 100, 'name' => 'operation-guest', 'is_template' => 0,
                'provisioned_size_bytes' => 1024, 'inventory_state' => 'active',
                'first_seen_run_id' => 'operation-run000', 'last_seen_run_id' => 'operation-run000',
                'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function manualCommand(string $request, string $key, string $policy = '80000000-0000-4000-8000-000000000001'): BackupOperationCommand
    {
        return new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            self::uuid($request), self::uuid($policy), self::uuid('50000000-0000-4000-8000-000000000101'),
            1, $key, 'operation-block1',
        );
    }

    private function restoreQemuPlacement(): void
    {
        $this->connection()->insert('guest_placements', [
            'guest_id'=>self::uuid('50000000-0000-4000-8000-000000000101'),
            'connection_id'=>self::uuid('10000000-0000-4000-8000-000000000001'),
            'cluster_id'=>self::uuid('30000000-0000-4000-8000-000000000001'),
            'node_id'=>self::uuid('40000000-0000-4000-8000-000000000001'),
            'placement_revision'=>1,'observed_at'=>'2026-07-13 00:00:00.000000',
            'sync_run_id'=>self::uuid('20000000-0000-4000-8000-000000000001'),
        ]);
    }

    private function restoreCapability(): void
    {
        $capabilities='{"profile":"qa"}';
        $this->connection()->insert('proxmox_capability_snapshots', [
            'id'=>self::uuid('b0000000-0000-4000-8000-000000000001'),
            'connection_id'=>self::uuid('10000000-0000-4000-8000-000000000001'),
            'product'=>'pve','version_major'=>9,'version_minor'=>0,'raw_version'=>'9.0.0',
            'profile_version'=>1,'capabilities_json'=>$capabilities,'snapshot_hash'=>hash('sha256',$capabilities,true),
            'first_observed_at'=>'2026-07-13 00:00:00.000000','last_observed_at'=>'2026-07-13 00:00:00.000000',
        ]);
    }

    private function seedQaFixture(FrozenClock $clock): void
    {
        $now = '2026-07-13 00:00:00.000000';
        $this->connection()->insert('users', [
            'id' => self::USER, 'username' => 'qa-admin', 'display_name' => 'QA Admin',
            'password_hash' => '$argon2id$fixture', 'enabled' => 1, 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        (new MariaDbQaFixtureSeeder($this->connection(), $clock))->seed();
    }

    private static function uuid(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        self::assertIsString($binary);

        return $binary;
    }

    private static function scalarString(mixed $value): string
    {
        self::assertTrue(is_string($value) || is_int($value));

        return (string) $value;
    }

    private function cleanupCommittedFixture(): void
    {
        $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection()->delete('backup_operation_commands', ['actor_user_id' => self::USER]);
            $this->connection()->delete('audit_events', ['actor_user_id' => self::USER]);
            $this->connection()->delete('backup_requests', ['id' => self::REQUEST]);
            $this->connection()->delete('guests', ['id' => self::GUEST]);
            $this->connection()->delete('backup_policies', ['id' => self::POLICY]);
            $this->connection()->delete('users', ['id' => self::USER]);
        } finally {
            $this->connection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
