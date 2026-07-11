<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsInstallationSnapshot
{
    /** @var list<PbsDatastoreDefinition> */
    public array $datastores;

    /** @var list<PbsDatastoreCapacity> */
    public array $capacities;

    /** @var list<PbsInventoryIssue> */
    public array $issues;

    /**
     * @param list<PbsDatastoreDefinition> $datastores
     * @param list<PbsDatastoreCapacity>   $capacities
     * @param list<PbsInventoryIssue>      $issues
     */
    public function __construct(
        public PbsVersion $version,
        public string $node,
        public ?PbsNodeStatus $nodeStatus,
        public ?PbsInstanceIdentity $instanceIdentity,
        public PbsDatastoreScanScope $scope,
        public ?PbsDatastoreConfigurationSnapshot $startConfiguration,
        public ?PbsDatastoreConfigurationSnapshot $endConfiguration,
        array $datastores,
        array $capacities,
        array $issues,
    ) {
        $definitionBackends = [];
        foreach ($datastores as $definition) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$definition instanceof PbsDatastoreDefinition || isset($definitionBackends[$definition->id->value])) {
                throw new InvalidArgumentException('The PBS datastore definitions are invalid.');
            }
            $definitionBackends[$definition->id->value] = $definition->backendType;
        }
        $capacityIds = [];
        foreach ($capacities as $capacity) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$capacity instanceof PbsDatastoreCapacity || isset($capacityIds[$capacity->id->value])) {
                throw new InvalidArgumentException('The PBS datastore capacities are invalid.');
            }
            $capacityIds[$capacity->id->value] = true;
            if (isset($definitionBackends[$capacity->id->value])
                && $definitionBackends[$capacity->id->value] !== $capacity->backendType) {
                throw new InvalidArgumentException('The PBS datastore backend evidence is inconsistent.');
            }
        }
        $this->datastores = $datastores;
        $this->capacities = $capacities;
        $this->issues = $issues;
    }

    public function isComplete(): bool
    {
        return [] === $this->issues
            && null !== $this->nodeStatus
            && $this->node === $this->nodeStatus->node
            && (!$this->version->supportsInstanceIdentity() || null !== $this->instanceIdentity)
            && null !== $this->startConfiguration
            && null !== $this->endConfiguration
            && $this->startConfiguration->sameConfiguration($this->endConfiguration)
            && $this->expectedDatastoreIds($this->startConfiguration) === $this->datastoreIds()
            && $this->datastoreBackends() === $this->capacityBackends();
    }

    public function permitsDeletionDecisions(): bool
    {
        return $this->isComplete();
    }

    /** @return list<string> */
    private function expectedDatastoreIds(PbsDatastoreConfigurationSnapshot $configuration): array
    {
        $ids = array_map(
            static fn (PbsDatastoreId $id): string => $id->value,
            $this->scope->installationWide ? $configuration->datastores : $this->scope->datastores,
        );
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return list<string> */
    private function datastoreIds(): array
    {
        $ids = array_map(static fn (PbsDatastoreDefinition $definition): string => $definition->id->value, $this->datastores);
        sort($ids, SORT_STRING);
        return $ids;
    }

    /** @return array<string, PbsDatastoreBackendType> */
    private function datastoreBackends(): array
    {
        $backends = [];
        foreach ($this->datastores as $definition) {
            $backends[$definition->id->value] = $definition->backendType;
        }
        ksort($backends, SORT_STRING);
        return $backends;
    }

    /** @return array<string, PbsDatastoreBackendType> */
    private function capacityBackends(): array
    {
        $backends = [];
        foreach ($this->capacities as $capacity) {
            $backends[$capacity->id->value] = $capacity->backendType;
        }
        ksort($backends, SORT_STRING);
        return $backends;
    }
}
