<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final class DbalBackupRequestGuestGuard
{
    /** @param list<string> $guestIds */
    public function lock(Connection $connection, array $guestIds): void
    {
        $unique = [];
        foreach ($guestIds as $guestId) {
            if (16 !== strlen($guestId)) {
                throw new RuntimeException('An invalid guest request guard identifier was provided.');
            }
            $unique[bin2hex($guestId)] = $guestId;
        }
        ksort($unique, SORT_STRING);

        foreach ($unique as $guestId) {
            $locked = $connection->fetchOne(
                'SELECT id FROM guests WHERE id = :guest FOR UPDATE',
                ['guest' => $guestId],
                ['guest' => ParameterType::BINARY],
            );
            if (!is_string($locked) || !hash_equals($guestId, $locked)) {
                throw new RuntimeException('The guest request guard no longer exists.');
            }
        }
    }

    public function activeRequestId(Connection $connection, string $guestId): ?string
    {
        $value = $connection->fetchOne(<<<'SQL'
SELECT id FROM backup_requests
WHERE guest_id = :guest
  AND state IN ('pending', 'retry_wait', 'leased', 'starting', 'running', 'reconcile_required')
ORDER BY id
LIMIT 1
FOR UPDATE
SQL, ['guest' => $guestId], ['guest' => ParameterType::BINARY]);
        if (false === $value) {
            return null;
        }
        if (!is_string($value) || 16 !== strlen($value)) {
            throw new RuntimeException('MariaDB returned an invalid active request identifier.');
        }

        return $value;
    }
}
