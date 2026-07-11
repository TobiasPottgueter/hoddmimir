<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PvePbsStorageMapping
{
    public function __construct(
        public string $server,
        public int $port,
        public string $datastore,
        public ?string $namespace,
    ) {
        if ('' === $server) {
            throw new InvalidArgumentException('The PBS server must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The PBS port must be between 1 and 65535.');
        }

        if ('' === $datastore) {
            throw new InvalidArgumentException('The PBS datastore must not be empty.');
        }

        if (null !== $namespace && '' === $namespace) {
            throw new InvalidArgumentException('The PBS namespace must be null or non-empty.');
        }
    }

    /** @return array{server: string, port: int, datastore: string, namespace: ?string} */
    public function signature(): array
    {
        return [
            'server' => $this->server,
            'port' => $this->port,
            'datastore' => $this->datastore,
            'namespace' => $this->namespace,
        ];
    }
}
