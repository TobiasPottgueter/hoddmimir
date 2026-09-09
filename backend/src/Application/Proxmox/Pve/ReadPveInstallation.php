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
        return self::readClient($this->connector->connect());
    }

    /**
     * Read the core inventory from an already established endpoint session.
     * Composite readers use this method so all scopes share exactly one
     * selected endpoint and one transport session.
     */
    public static function readClient(PveReadClient $client): PveInstallationSnapshot
    {
        return new PveInstallationSnapshot(
            $client->version(),
            $client->permissions(),
            $client->topology(),
            $client->resources(),
        );
    }
}
