<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

interface PveApiTransport
{
    /**
     * @param list<string>                         $pathSegments
     * @param array<string, string|int|bool|null> $query
     */
    public function get(array $pathSegments, array $query = []): mixed;
}
