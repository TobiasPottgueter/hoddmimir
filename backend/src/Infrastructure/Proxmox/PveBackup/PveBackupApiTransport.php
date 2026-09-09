<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

interface PveBackupApiTransport
{
    /**
     * @param list<string>                         $pathSegments
     * @param array<string, string|int|bool|null> $query
     */
    public function get(array $pathSegments, array $query = []): mixed;

    /**
     * @param list<string>              $pathSegments
     * @param array<string, string|int> $form
     */
    public function post(array $pathSegments, array $form): PveBackupWriteTransportResult;

    /** @param list<string> $pathSegments */
    public function delete(array $pathSegments): PveBackupWriteTransportResult;
}
