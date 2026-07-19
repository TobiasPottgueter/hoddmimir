<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class ReadPbsInstallation
{
    public function __construct(
        private PbsReadConnector $connector,
        private PbsDatastoreScanScope $scope,
        private int $maximumDatastoreFanout = 128,
    ) {
        if ($maximumDatastoreFanout < 1 || $maximumDatastoreFanout > 1024) {
            throw new \InvalidArgumentException('The PBS datastore fanout must be between 1 and 1024.');
        }
    }

    public function read(): PbsInstallationSnapshot
    {
        $client = $this->connector->connect();
        $version = $client->version();
        $node = PbsNodeRoute::Local->value;
        $issues = [];

        $systemPermission = $this->permission($client, '/system/status');
        if (null === $systemPermission || !$systemPermission->grants('Sys.Audit')) {
            $issues[] = new PbsInventoryIssue(
                PbsInventoryIssueCode::MissingSystemStatusPermission,
                '/access/permissions?path=/system/status',
            );
        }

        $nodeStatus = null;
        try {
            $nodeStatus = $client->nodeStatus();
        } catch (PbsReadFailure) {
            $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::NodeStatusReadFailed, sprintf('/nodes/%s/status', $node));
        }

        $identity = null;
        if ($version->supportsInstanceIdentity()) {
            try {
                $identity = $client->instanceIdentity();
            } catch (PbsReadFailure) {
                $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::IdentityReadFailed, sprintf('/nodes/%s/identity', $node));
            }
        }

        $permitted = $this->assessDatastorePermissions($client, $issues);
        $startConfiguration = $this->configuration($client, $issues);
        $definitions = $this->definitions($client, $issues);

        $expected = $this->expectedDatastores($startConfiguration);
        $fanoutExceeded = count($expected) > $this->maximumDatastoreFanout;
        if ($fanoutExceeded) {
            $issues[] = new PbsInventoryIssue(
                PbsInventoryIssueCode::DatastoreFanoutExceeded,
                '/config/datastore',
            );
        }
        /** @var array<string, PbsDatastoreDefinition> $definitionsById */
        $definitionsById = [];
        foreach ($definitions as $definition) {
            $definitionsById[$definition->id->value] = $definition;
        }

        if ($this->scope->installationWide) {
            foreach ($definitionsById as $id => $definition) {
                if (!isset($expected[$id])) {
                    $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::UnexpectedDatastore, '/admin/datastore', $id);
                }
            }
        }

        /** @var list<PbsDatastoreDefinition> $selectedDefinitions */
        $selectedDefinitions = [];
        $capacities = [];
        foreach ($expected as $id => $datastoreId) {
            $definition = $definitionsById[$id] ?? null;
            if (null === $definition) {
                $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::MissingDatastore, '/admin/datastore', $id);
                continue;
            }

            $selectedDefinitions[] = $definition;
            if ($fanoutExceeded || (!isset($permitted['*']) && !isset($permitted[$id]))) {
                continue;
            }

            try {
                $capacities[] = $client->datastoreStatus($datastoreId, $definition->backendType);
            } catch (PbsReadFailure) {
                $issues[] = new PbsInventoryIssue(
                    PbsInventoryIssueCode::DatastoreStatusReadFailed,
                    sprintf('/admin/datastore/%s/status', rawurlencode($id)),
                    $id,
                );
            }
        }

        $endConfiguration = $this->configuration($client, $issues);
        if (null !== $startConfiguration && null !== $endConfiguration
            && !$startConfiguration->sameConfiguration($endConfiguration)) {
            $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::ConfigurationChanged, '/config/datastore');
        }
        $this->assessExplicitScopeConfiguration($startConfiguration, $endConfiguration, $issues);

        return new PbsInstallationSnapshot(
            $version,
            $nodeStatus,
            $identity,
            $this->scope,
            $startConfiguration,
            $endConfiguration,
            $selectedDefinitions,
            $capacities,
            $issues,
        );
    }

    /**
     * @param list<PbsInventoryIssue> $issues
     * @return array<string, true>
     */
    private function assessDatastorePermissions(PbsReadClient $client, array &$issues): array
    {
        if ($this->scope->installationWide) {
            $permission = $this->permission($client, '/datastore');
            if (null === $permission || !$permission->grants('Datastore.Audit')) {
                $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::MissingDatastorePermission, '/access/permissions?path=/datastore');
                return [];
            }
            if (!$permission->propagates('Datastore.Audit')) {
                $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::MissingDatastorePropagation, '/access/permissions?path=/datastore');
                return [];
            }

            return ['*' => true];
        }

        $permitted = [];
        foreach ($this->scope->datastores as $datastore) {
            $path = '/datastore/'.$datastore->value;
            $permission = $this->permission($client, $path);
            if (null === $permission || !$permission->grants('Datastore.Audit')) {
                $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::MissingDatastorePermission, '/access/permissions?path='.$path, $datastore->value);
                continue;
            }
            $permitted[$datastore->value] = true;
        }

        return $permitted;
    }

    private function permission(PbsReadClient $client, string $path): ?PbsEffectivePermission
    {
        try {
            return $client->permission($path);
        } catch (PbsReadFailure) {
            return null;
        }
    }

    /** @param list<PbsInventoryIssue> $issues */
    private function assessExplicitScopeConfiguration(
        ?PbsDatastoreConfigurationSnapshot $startConfiguration,
        ?PbsDatastoreConfigurationSnapshot $endConfiguration,
        array &$issues,
    ): void {
        if ($this->scope->installationWide || null === $startConfiguration || null === $endConfiguration) {
            return;
        }

        $startIds = array_fill_keys(
            array_map(static fn (PbsDatastoreId $id): string => $id->value, $startConfiguration->datastores),
            true,
        );
        $endIds = array_fill_keys(
            array_map(static fn (PbsDatastoreId $id): string => $id->value, $endConfiguration->datastores),
            true,
        );
        foreach ($this->scope->datastores as $datastore) {
            if (!isset($startIds[$datastore->value]) || !isset($endIds[$datastore->value])) {
                $issues[] = new PbsInventoryIssue(
                    PbsInventoryIssueCode::MissingDatastoreConfiguration,
                    '/config/datastore',
                    $datastore->value,
                );
            }
        }
    }

    /** @param list<PbsInventoryIssue> $issues */
    private function configuration(PbsReadClient $client, array &$issues): ?PbsDatastoreConfigurationSnapshot
    {
        try {
            return $client->datastoreConfigurations();
        } catch (PbsReadFailure) {
            $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::ConfigurationReadFailed, '/config/datastore');
            return null;
        }
    }

    /**
     * @param list<PbsInventoryIssue> $issues
     * @return list<PbsDatastoreDefinition>
     */
    private function definitions(PbsReadClient $client, array &$issues): array
    {
        try {
            return $client->datastores();
        } catch (PbsReadFailure) {
            $issues[] = new PbsInventoryIssue(PbsInventoryIssueCode::DatastoreListReadFailed, '/admin/datastore');
            return [];
        }
    }

    /** @return array<string, PbsDatastoreId> */
    private function expectedDatastores(?PbsDatastoreConfigurationSnapshot $configuration): array
    {
        if ($this->scope->installationWide) {
            $datastores = null === $configuration ? [] : $configuration->datastores;
        } else {
            $datastores = $this->scope->datastores;
        }
        $expected = [];
        foreach ($datastores as $datastore) {
            $expected[$datastore->value] = $datastore;
        }
        ksort($expected, SORT_STRING);
        return $expected;
    }
}
