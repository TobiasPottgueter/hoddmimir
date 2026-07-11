<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Security\ReferencedCredentialKeyIds;
use Doctrine\DBAL\Connection;
use RuntimeException;

final readonly class DbalReferencedCredentialKeyIds implements ReferencedCredentialKeyIds
{
    public function __construct(private Connection $connection)
    {
    }

    public function referencedKeyIds(): array
    {
        $values = $this->connection->fetchFirstColumn(
            'SELECT key_id FROM credential_key_usage ORDER BY key_id',
        );
        $keyIds = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Credential key usage is unavailable.');
            }
            $keyIds[] = $value;
        }

        return $keyIds;
    }
}
