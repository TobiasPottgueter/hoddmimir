<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class ReadPveInstallation
{
    public function __construct(private PveReadConnector $connector)
    {
    }

    public function read(): PveInstallationSnapshot
    {
        $client = $this->connector->connect();

        return new PveInstallationSnapshot(
            $client->version(),
            $client->permissions(),
            $client->topology(),
            $client->resources(),
        );
    }
}
