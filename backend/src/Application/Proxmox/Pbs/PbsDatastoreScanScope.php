<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsDatastoreScanScope
{
    /** @var list<PbsDatastoreId> */
    public array $datastores;

    /** @param list<PbsDatastoreId> $datastores */
    private function __construct(public bool $installationWide, array $datastores)
    {
        if ($installationWide === ([] !== $datastores)) {
            throw new InvalidArgumentException('The PBS datastore scan scope is invalid.');
        }

        $byId = [];
        foreach ($datastores as $datastore) {
            $byId[$datastore->value] = $datastore;
        }
        if (count($byId) !== count($datastores)) {
            throw new InvalidArgumentException('The PBS datastore scan scope contains duplicates.');
        }

        ksort($byId, SORT_STRING);
        $this->datastores = array_values($byId);
    }

    public static function installationWide(): self
    {
        return new self(true, []);
    }

    /** @param non-empty-list<PbsDatastoreId> $datastores */
    public static function explicit(array $datastores): self
    {
        return new self(false, $datastores);
    }
}
