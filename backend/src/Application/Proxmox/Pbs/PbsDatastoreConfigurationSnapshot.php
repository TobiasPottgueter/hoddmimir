<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsDatastoreConfigurationSnapshot
{
    /** @var list<PbsDatastoreId> */
    public array $datastores;

    /** @param list<PbsDatastoreId> $datastores */
    public function __construct(public string $digest, array $datastores)
    {
        $this->datastores = $datastores;
    }

    public function sameConfiguration(self $other): bool
    {
        return $this->digest === $other->digest
            && array_map(static fn (PbsDatastoreId $id): string => $id->value, $this->datastores)
                === array_map(static fn (PbsDatastoreId $id): string => $id->value, $other->datastores);
    }
}
