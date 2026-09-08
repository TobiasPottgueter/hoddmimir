<?php

declare(strict_types=1);

namespace App\Infrastructure\Maintenance;

use App\Application\Backup\Notification\MatrixNotificationFormatter;
use App\Application\Backup\Queue\QueueClaimTokenSource;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Domain\Backup\BackupProblemCode;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\UserDisplayName;
use App\Domain\Security\UserId;
use App\Domain\Shared\Clock;
use App\Infrastructure\Persistence\MariaDb\DbalBackupNotificationDeliveryStore;
use App\Infrastructure\Persistence\MariaDb\DbalBackupProblemRecorder;
use App\Infrastructure\Persistence\MariaDb\DbalLocalAuthStore;
use App\Infrastructure\Persistence\MariaDb\DbalOperationsReadModel;
use Doctrine\DBAL\Connection;

/** Real application reads/writes, always rolled back and never externally delivered. */
final readonly class MaintenanceFunctionalValidator
{
    public function __construct(private Connection $connection, private SecretCipher $cipher, private Clock $clock) {}

    public function validate(): void
    {
        $user = $this->connection->fetchOne('SELECT CURRENT_USER()');
        if (!is_string($user)) throw new \RuntimeException('Invalid validation database identity.');
        $role = explode('@', $user, 2)[0];
        $this->connection->beginTransaction();
        try {
            match ($role) {
                'hoddmimir_migration' => $this->credentials(),
                'hoddmimir_web' => $this->web(),
                'hoddmimir_collector' => $this->outbox(false),
                'hoddmimir_backup_worker' => $this->outbox(true),
                default => throw new \RuntimeException('Unsupported validation database role.'),
            };
        } finally {
            $this->connection->rollBack();
        }
    }

    private function credentials(): void
    {
        $rows = $this->connection->executeQuery(<<<'SQL'
SELECT credential.id, credential.purpose, credential.secret_envelope, connection.product
FROM proxmox_credentials credential JOIN proxmox_connections connection ON connection.id=credential.connection_id
SQL);
        foreach ($rows->iterateAssociative() as $row) {
            $id = $row['id'] ?? null;
            $envelope = $row['secret_envelope'] ?? null;
            if (!is_string($id) || !is_string($envelope)) throw new \RuntimeException('Invalid stored credential.');
            $purpose = match ([$row['product'], $row['purpose']]) {
                ['pve', 'collector'] => SecretPurpose::PveCollectorToken,
                ['pbs', 'collector'] => SecretPurpose::PbsCollectorToken,
                ['pve', 'backup'] => SecretPurpose::PveBackupToken,
                default => throw new \RuntimeException('Invalid stored credential purpose.'),
            };
            $this->cipher->decrypt(EncryptedSecret::fromEncoded($envelope), SecretContext::forBinaryCredentialId($id, $purpose));
        }
        $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
    }

    private function web(): void
    {
        $reads = new DbalOperationsReadModel($this->connection, $this->clock);
        $reads->dashboard(true);
        $reads->notifications(new PageRequest(1), null);
        $reads->queue(new PageRequest(1), null);
        $reads->runs(new PageRequest(1), null);
        $id = new UserId(random_bytes(16));
        $username = new NormalizedUsername('maintenance-'.bin2hex($id->binary()));
        $auth = new DbalLocalAuthStore($this->connection);
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
        $auth->createAdmin($id, $username, new UserDisplayName('Maintenance validation'), new PasswordHash($hash), $this->clock->now());
        $identity = $auth->findEnabled($username);
        if (null === $identity) throw new \RuntimeException('Validation user could not be read.');
        $admin = $auth->findAdmin($username);
        if (null === $admin) throw new \RuntimeException('Validation administrator has no role.');
        $expected = array_map(static fn (Permission $permission): string => $permission->value, Permission::cases());
        sort($expected);
        $permissions = array_map(static fn (Permission $permission): string => $permission->value, $identity->permissions);
        sort($permissions);
        if ($expected !== $permissions) throw new \RuntimeException('Administrator permissions are incomplete.');
        $this->connection->executeStatement("DELETE FROM user_roles WHERE user_id=:id", ['id' => $id->binary()]);
        $this->connection->executeStatement("INSERT INTO user_roles (user_id, role_id, assigned_at) SELECT :id, id, :at FROM roles WHERE role_name='viewer'", ['id' => $id->binary(), 'at' => $this->clock->now()->format('Y-m-d H:i:s.u')]);
        $viewer = $auth->findEnabled($username);
        if (null === $viewer || [Permission::InventoryRead] !== $viewer->permissions) throw new \RuntimeException('Viewer permissions differ from the read-only contract.');

    }

    private function outbox(bool $deliver): void
    {
        $context = [
            'connection_id' => random_bytes(16), 'cluster_id' => random_bytes(16), 'guest_id' => random_bytes(16),
            'policy_id' => random_bytes(16), 'target_id' => random_bytes(16), 'attempt' => 1,
            'guest_name' => 'maintenance-validation', 'guest_type' => 'qemu', 'vmid' => 999999999,
            'node_name' => 'maintenance-validation', 'target_label' => 'maintenance-validation',
        ];
        // Reserve an earlier test instant so claiming never mutates an actual pending delivery.
        $at = new \DateTimeImmutable('1000-01-02T00:00:00Z');
        $earliest = $this->connection->fetchOne('SELECT MIN(available_at) FROM backup_notification_outbox');
        if (is_string($earliest) && $earliest <= $at->format('Y-m-d H:i:s.u')) throw new \RuntimeException('Cannot isolate the outbox validation.');
        $occurrence = random_bytes(16);
        $recorder = new DbalBackupProblemRecorder();
        $recorder->prePost($this->connection, $context, $occurrence, BackupProblemCode::CapacityBlocked, 'minimum_free_space.insufficient_free_space', $at, $at->modify('+2 minutes'));
        $recorder->prePost($this->connection, $context, $occurrence, BackupProblemCode::CapacityBlocked, 'minimum_free_space.insufficient_free_space', $at, $at->modify('+2 minutes'));
        $count = $this->connection->fetchOne('SELECT COUNT(*) FROM backup_notification_outbox WHERE occurrence_id=:id', ['id' => $occurrence]);
        if (1 !== $count && '1' !== $count) {
            throw new \RuntimeException('The outbox write is not idempotent.');
        }
        if ($deliver) {
            $tokens = new class implements QueueClaimTokenSource { public function next(): string { return random_bytes(16); } };
            $store = new DbalBackupNotificationDeliveryStore($this->connection, $tokens);
            $claim = $store->claim($at);
            if (null === $claim || $claim->notification->eventId !== $this->connection->fetchOne('SELECT id FROM backup_notification_outbox WHERE occurrence_id=:id', ['id' => $occurrence])) throw new \RuntimeException('The outbox reader did not claim the validation event.');
            (new MatrixNotificationFormatter())->format($claim->notification);
            $store->markSent($claim, $at);
        }
    }
}
