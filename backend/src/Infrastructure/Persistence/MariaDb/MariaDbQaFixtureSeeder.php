<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Qa\QaFixtureSeeder;
use App\Domain\Shared\Clock;
use DateTimeZone;
use Doctrine\DBAL\Connection;

final readonly class MariaDbQaFixtureSeeder implements QaFixtureSeeder
{
    private const string VIEWER = '01000000-0000-4000-8000-000000000001';
    private const string CONNECTION = '10000000-0000-4000-8000-000000000001';
    private const string RUN = '20000000-0000-4000-8000-000000000001';
    private const string CYCLE = '20000000-0000-4000-8000-000000000002';
    private const string CLUSTER = '30000000-0000-4000-8000-000000000001';
    private const string NODE_A = '40000000-0000-4000-8000-000000000001';
    private const string NODE_B = '40000000-0000-4000-8000-000000000002';
    private const string QEMU = '50000000-0000-4000-8000-000000000101';
    private const string LXC = '50000000-0000-4000-8000-000000000201';
    private const string STORAGE = '60000000-0000-4000-8000-000000000001';
    private const string TARGET = '70000000-0000-4000-8000-000000000001';
    private const string POLICY = '80000000-0000-4000-8000-000000000001';
    private const string GLOBAL_ASSIGNMENT = '90000000-0000-4000-8000-000000000001';
    private const string EXCLUDE_ASSIGNMENT = '90000000-0000-4000-8000-000000000002';
    private const string GUEST_OVERRIDE = 'a0000000-0000-4000-8000-000000000001';
    private const string CAPABILITY = 'b0000000-0000-4000-8000-000000000001';
    private const string REQUEST = 'c0000000-0000-4000-8000-000000000001';
    private const string BACKUP_RUN = 'd0000000-0000-4000-8000-000000000001';
    private const string NOTIFICATION = 'e0000000-0000-4000-8000-000000000001';
    private const string EVALUATION = 'e1000000-0000-4000-8000-000000000001';
    private const string DECISION = 'e2000000-0000-4000-8000-000000000001';
    private const string STALE_COLLECTOR = 'e3000000-0000-4000-8000-000000000001';

    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    public function seed(): void
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $staleStarted = $this->clock->now()->modify('-20 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $staleHeartbeat = $this->clock->now()->modify('-10 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $staleExpires = $this->clock->now()->modify('-5 minutes')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $this->connection->transactional(function () use ($now, $staleStarted, $staleHeartbeat, $staleExpires): void {
                $this->removeFixture();
                $this->seedViewer($now);
                $connection = $this->id(self::CONNECTION);
                $run = $this->id(self::RUN);
                $cluster = $this->id(self::CLUSTER);
                $nodeA = $this->id(self::NODE_A);
                $nodeB = $this->id(self::NODE_B);
                $storage = $this->id(self::STORAGE);
                $target = $this->id(self::TARGET);
                $policy = $this->id(self::POLICY);

                $this->connection->insert('proxmox_connections', [
                    'id' => $connection, 'display_name' => 'QA PVE', 'product' => 'pve',
                    'enabled' => 1, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $capabilities = '{"profile":"qa"}';
                $this->connection->insert('proxmox_capability_snapshots', [
                    'id'=>$this->id(self::CAPABILITY),'connection_id'=>$connection,'product'=>'pve','version_major'=>9,'version_minor'=>0,
                    'raw_version'=>'9.0.0','profile_version'=>1,'capabilities_json'=>$capabilities,'snapshot_hash'=>hash('sha256',$capabilities,true),
                    'first_observed_at'=>$now,'last_observed_at'=>$now,
                ]);
                $this->connection->insert('worker_heartbeats', [
                    'worker_instance_id' => $this->id(self::STALE_COLLECTOR),
                    'worker_kind' => 'collector',
                    'status' => 'degraded',
                    'started_at' => $staleStarted,
                    'heartbeat_at' => $staleHeartbeat,
                    'expires_at' => $staleExpires,
                    'current_activity' => 'qa_stale_heartbeat',
                    'current_cycle_token' => null,
                    'next_action_at' => null,
                    'build_version' => 'qa-fixture',
                ]);
                $this->connection->insert('inventory_sync_runs', [
                    'id' => $run, 'cycle_token' => $this->id(self::CYCLE), 'collector_fencing_token' => 1,
                    'connection_id' => $connection, 'expected_connection_revision' => 1,
                    'status' => 'succeeded', 'authoritative' => 1, 'started_at' => $now,
                    'heartbeat_at' => $now, 'finished_at' => $now, 'applied_at' => $now,
                    'nodes_seen' => 2, 'guests_seen' => 2, 'storages_seen' => 1,
                ]);
                $this->connection->insert('proxmox_connection_onboarding_state', [
                    'connection_id' => $connection,
                    'state' => 'inventory_verified',
                    'tls_verified' => 1,
                    'product_supported' => 1,
                    'scan_permissions_verified' => 1,
                    'backup_permissions_verified' => 1,
                    'detected_product' => 'pve',
                    'detected_version' => '9.0.0',
                    'warnings_json' => '[]',
                    'verified_at' => $now,
                    'inventory_status_changed_at' => $now,
                    'last_inventory_run_id' => $run,
                ]);
                $this->connection->insert('pve_clusters', [
                    'id' => $cluster, 'connection_id' => $connection, 'external_name' => 'qa-cluster',
                    'topology' => 'clustered', 'inventory_state' => 'active',
                    'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => $now, 'last_seen_at' => $now,
                ]);
                foreach ([[$nodeA, 'qa-node-a'], [$nodeB, 'qa-node-b']] as [$node, $name]) {
                    $this->connection->insert('pve_nodes', [
                        'id' => $node, 'connection_id' => $connection, 'cluster_id' => $cluster,
                        'node_name' => $name, 'api_status' => 'online', 'inventory_state' => 'active',
                        'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                        'first_seen_at' => $now, 'last_seen_at' => $now,
                    ]);
                }
                foreach ([[self::QEMU, 'qemu', 101, 'qa-qemu-101', $nodeA], [self::LXC, 'lxc', 201, 'qa-lxc-201', $nodeB]] as [$id, $type, $vmid, $name, $node]) {
                    $guest = $this->id($id);
                    $this->connection->insert('guests', [
                        'id' => $guest, 'connection_id' => $connection, 'cluster_id' => $cluster,
                        'guest_type' => $type, 'vmid' => $vmid, 'name' => $name, 'is_template' => 0,
                        'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                        'first_seen_at' => $now, 'last_seen_at' => $now, 'provisioned_size_bytes' => 1073741824,
                    ]);
                    $this->connection->insert('guest_placements', [
                        'guest_id' => $guest, 'connection_id' => $connection, 'cluster_id' => $cluster,
                        'node_id' => $node, 'observed_at' => $now, 'sync_run_id' => $run,
                    ]);
                }
                $this->connection->insert('pve_storages', [
                    'id' => $storage, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'storage_name' => 'qa-backup', 'storage_type' => 'dir', 'supports_backup' => 1,
                    'disabled' => 0, 'content_json' => '["backup"]', 'shared' => 1,
                    'inventory_state' => 'active', 'first_seen_run_id' => $run, 'last_seen_run_id' => $run,
                    'first_seen_at' => $now, 'last_seen_at' => $now,
                ]);
                foreach ([$nodeA, $nodeB] as $node) {
                    $this->connection->insert('pve_node_storage_state', [
                        'connection_id' => $connection, 'cluster_id' => $cluster, 'node_id' => $node,
                        'storage_id' => $storage, 'enabled' => 1, 'active' => 1, 'shared' => 1,
                        'capacity_status' => 'measured', 'total_bytes' => 1099511627776,
                        'used_bytes' => 107374182400, 'available_bytes' => 992137445376,
                        'observed_at' => $now, 'sync_run_id' => $run,
                    ]);
                }
                $this->connection->insert('backup_targets', [
                    'id' => $target, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'storage_id' => $storage, 'display_name' => 'QA Existing Target', 'status' => 'enabled',
                    'revision' => 1, 'minimum_free_bytes' => 10737418240, 'fixed_parallel_limit' => 2,
                    'created_at' => $now, 'updated_at' => $now, 'disabled_at' => null,
                ]);
                foreach ([$nodeA, $nodeB] as $node) {
                    $this->connection->insert('backup_target_allowed_nodes', [
                        'target_id' => $target, 'connection_id' => $connection,
                        'cluster_id' => $cluster, 'node_id' => $node, 'created_at' => $now,
                    ]);
                }
                $this->connection->insert('backup_policies', [
                    'id' => $policy, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'target_id' => $target, 'display_name' => 'QA Existing Policy', 'status' => 'enabled',
                    'revision' => 1, 'policy_priority' => 300, 'backup_mode' => 'snapshot',
                    'compression' => 'zstd', 'maximum_age_seconds' => 86400,
                    'schedule' => 'collector_cycle', 'keep_last' => 7,
                    'failure_notification_recipients_json' => '["ops@example.invalid"]',
                    'retention_execution_enabled' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ([[self::GLOBAL_ASSIGNMENT, 'global', null, 'include'], [self::EXCLUDE_ASSIGNMENT, 'guest', self::QEMU, 'exclude']] as [$id, $scope, $guestId, $value]) {
                    $this->connection->insert('backup_policy_assignments', [
                        'id' => $this->id($id), 'policy_id' => $policy, 'connection_id' => $connection,
                        'cluster_id' => $cluster, 'scope' => $scope,
                        'subject_connection_id' => null === $guestId ? null : $connection,
                        'subject_cluster_id' => null === $guestId ? null : $cluster,
                        'guest_id' => null === $guestId ? null : $this->id($guestId),
                        'selection_value' => $value, 'status' => 'active', 'revision' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $this->connection->insert('backup_policy_guest_overrides', [
                    'id' => $this->id(self::GUEST_OVERRIDE), 'policy_id' => $policy,
                    'connection_id' => $connection, 'cluster_id' => $cluster,
                    'guest_id' => $this->id(self::LXC), 'backup_mode' => 'snapshot',
                    'compression' => 'zstd', 'keep_last' => 3, 'status' => 'active', 'revision' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $decisionPayload = '{"fixture":"qa-never-backed-up"}';
                $this->connection->insert('scheduler_evaluation_runs', [
                    'id' => $this->id(self::EVALUATION), 'cycle_token' => $this->id(self::CYCLE),
                    'collector_fencing_token' => 1, 'evaluator_version' => 1,
                    'payload_hash' => hash('sha256', $decisionPayload, true),
                    'decision_count' => 1, 'gate_count' => 0,
                    'started_at' => $now, 'completed_at' => $now, 'persisted_at' => $now,
                ]);
                $this->connection->insert('scheduler_decisions', [
                    'id' => $this->id(self::DECISION), 'evaluation_run_id' => $this->id(self::EVALUATION),
                    'decision_ordinal' => 1, 'connection_id' => $connection, 'cluster_id' => $cluster,
                    'guest_id' => $this->id(self::QEMU), 'node_id' => $nodeA,
                    'placement_revision' => 1, 'placement_observed_at' => $now,
                    'outcome' => 'eligible', 'reason' => 'never_backed_up', 'priority' => 300,
                    'policy_id' => $policy, 'policy_revision' => 1,
                    'policy_snapshot_hash' => hash('sha256', $decisionPayload, true),
                    'target_id' => $target, 'target_revision' => 1,
                    'inventory_observed_at' => $now, 'capacity_observed_at' => $now,
                ]);
                $resolved='{"version":2,"mode":"snapshot","compression":"zstd","desiredRetention":{"prune-backups":{"keep-last":7}},"failureNotificationRecipients":["ops@example.invalid"]}';
                $request=$this->id(self::REQUEST);$backupRun=$this->id(self::BACKUP_RUN);
                $this->connection->insert('backup_requests',[
                    'id'=>$request,'root_request_id'=>$request,'attempt'=>1,'origin'=>'manual','state'=>'succeeded','reason'=>'manual','priority'=>400,
                    'scheduled_at'=>$now,'available_at'=>$now,'connection_id'=>$connection,'cluster_id'=>$cluster,'guest_id'=>$this->id(self::LXC),'node_id'=>$nodeB,
                    'placement_revision'=>1,'placement_observed_at'=>$now,'policy_id'=>$policy,'policy_revision'=>1,'target_id'=>$target,'target_revision'=>1,
                    'resolved_policy_json'=>$resolved,'resolved_policy_hash'=>hash('sha256',$resolved,true),'expected_size_bytes'=>1073741824,
                    'retry_disposition'=>'controlled_allowed','submission_provenance'=>'accepted','revision'=>3,'run_id'=>$backupRun,
                    'terminal_code'=>'success','terminal_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
                ]);
                $upid='UPID:qa-node-b:00000001:00000001:00000001:vzdump:201:qa@pve:';
                $this->connection->insert('backup_runs',['id'=>$backupRun,'request_id'=>$request,'root_request_id'=>$request,'attempt'=>1,'state'=>'succeeded','claim_token'=>$this->id(self::CYCLE),'claim_fence'=>1,'submission_provenance'=>'accepted','upid'=>$upid,'upid_hash'=>hash('sha256',$upid,true),'started_at'=>$now,'finished_at'=>$now,'exit_status'=>'OK','status_observed_at'=>$now,'revision'=>2]);
                $this->connection->insert('backup_request_events',['id'=>$this->id('f0000000-0000-4000-8000-000000000001'),'request_id'=>$request,'sequence_no'=>1,'event_type'=>'manual_requested','state'=>'pending','occurred_at'=>$now]);
                $this->connection->insert('backup_run_events',['id'=>$this->id('f0000000-0000-4000-8000-000000000002'),'run_id'=>$backupRun,'sequence_no'=>1,'event_type'=>'task_succeeded','state'=>'succeeded','occurred_at'=>$now]);
                $this->connection->insert('backup_run_log_entries',['run_id'=>$backupRun,'line_no'=>0,'observed_at'=>$now,'content'=>'QA sanitized backup log']);
                $payload=json_encode(['consecutiveFailures'=>1,'detailCode'=>null,'guestName'=>'qa-lxc-201','guestType'=>'lxc','nextRetryAt'=>null,'node'=>'qa-node-b','openedAt'=>$this->clock->now()->modify('-1 hour')->format('Y-m-d\TH:i:s.u\Z'),'occurredAt'=>$this->clock->now()->format('Y-m-d\TH:i:s.u\Z'),'problemCode'=>'task_failed','targetLabel'=>'QA Existing Target','vmid'=>201],JSON_THROW_ON_ERROR);
                $this->connection->insert('backup_notification_outbox',['id'=>$this->id(self::NOTIFICATION),'obligation_id'=>$this->id(self::REQUEST),'occurrence_id'=>$backupRun,'root_request_id'=>$request,'request_id'=>$request,'run_id'=>$backupRun,'notification_kind'=>'recovery','event_key'=>'qa.recovery','attempt'=>1,'check_number'=>1,'payload_json'=>$payload,'state'=>'sent','delivery_attempts'=>1,'available_at'=>$now,'created_at'=>$now,'sent_at'=>$now]);
            });
        } finally {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function removeFixture(): void
    {
        $viewer = $this->id(self::VIEWER);
        $this->connection->delete('user_roles', ['user_id' => $viewer]);
        $this->connection->delete('users', ['id' => $viewer]);
        $this->connection->delete('worker_heartbeats', ['worker_instance_id' => $this->id(self::STALE_COLLECTOR)]);
        $this->connection->delete('backup_target_allowed_nodes', ['target_id' => $this->id(self::TARGET)]);
        $this->connection->delete('scheduler_decisions', ['id' => $this->id(self::DECISION)]);
        $this->connection->delete('scheduler_evaluation_runs', ['id' => $this->id(self::EVALUATION)]);
        $this->connection->delete('backup_notification_outbox',['id'=>$this->id(self::NOTIFICATION)]);
        $this->connection->delete('backup_run_log_entries',['run_id'=>$this->id(self::BACKUP_RUN)]);
        $this->connection->delete('backup_run_events',['run_id'=>$this->id(self::BACKUP_RUN)]);
        $this->connection->delete('backup_request_events',['request_id'=>$this->id(self::REQUEST)]);
        $this->connection->delete('backup_runs',['id'=>$this->id(self::BACKUP_RUN)]);
        $this->connection->delete('backup_requests',['id'=>$this->id(self::REQUEST)]);
        $this->connection->delete('pve_node_storage_state', ['storage_id' => $this->id(self::STORAGE)]);
        foreach ([self::QEMU, self::LXC] as $guest) {
            $this->connection->delete('guest_placements', ['guest_id' => $this->id($guest)]);
        }
        foreach ([
            ['backup_policy_guest_overrides', 'id', self::GUEST_OVERRIDE],
            ['backup_policy_assignments', 'id', self::EXCLUDE_ASSIGNMENT],
            ['backup_policy_assignments', 'id', self::GLOBAL_ASSIGNMENT],
            ['backup_policies', 'id', self::POLICY],
            ['backup_targets', 'id', self::TARGET],
            ['guests', 'id', self::LXC],
            ['guests', 'id', self::QEMU],
            ['pve_storages', 'id', self::STORAGE],
            ['pve_nodes', 'id', self::NODE_B],
            ['pve_nodes', 'id', self::NODE_A],
            ['pve_clusters', 'id', self::CLUSTER],
            ['inventory_sync_runs', 'id', self::RUN],
            ['proxmox_connections', 'id', self::CONNECTION],
            ['proxmox_capability_snapshots', 'id', self::CAPABILITY],
        ] as [$table, $column, $id]) {
            $this->connection->delete($table, [$column => $this->id($id)]);
        }
    }

    private function seedViewer(string $now): void
    {
        $admin = $this->connection->fetchAssociative("SELECT id, password_hash FROM users WHERE username = 'qa-admin'");
        $viewerRole = $this->connection->fetchOne("SELECT id FROM roles WHERE role_name = 'viewer'");
        if (false === $admin || !is_string($admin['id'] ?? null) || !is_string($admin['password_hash'] ?? null) || !is_string($viewerRole)) {
            throw new \RuntimeException('The QA administrator or closed viewer role is unavailable.');
        }
        $viewer = $this->id(self::VIEWER);
        $this->connection->insert('users', [
            'id' => $viewer, 'username' => 'qa-viewer', 'display_name' => 'QA Viewer',
            'password_hash' => $admin['password_hash'], 'enabled' => 1, 'revision' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->connection->insert('user_roles', [
            'user_id' => $viewer, 'role_id' => $viewerRole, 'assigned_at' => $now,
            'assigned_by_user_id' => $admin['id'],
        ]);
    }

    private function id(string $uuid): string
    {
        $binary = hex2bin(str_replace('-', '', $uuid));
        if (false === $binary || 16 !== strlen($binary)) {
            throw new \LogicException('The QA fixture contains an invalid identifier.');
        }

        return $binary;
    }
}
