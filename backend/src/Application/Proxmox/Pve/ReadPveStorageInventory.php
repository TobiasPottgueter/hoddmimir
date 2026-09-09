<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use App\Domain\Shared\Clock;

final readonly class ReadPveStorageInventory
{
    public function __construct(
        private Clock $clock,
        private int $maximumNodeFanout = 128,
    ) {
        if ($maximumNodeFanout < 1 || $maximumNodeFanout > 1024) {
            throw new \InvalidArgumentException('The PVE storage node fanout must be between 1 and 1024.');
        }
    }

    public function read(
        PveReadClient $client,
        PvePermissionAssessment $permissions,
        PveClusterTopology $topology,
    ): PveStorageInventorySnapshot {
        $issues = [];

        if (!$this->hasStoragePermissionCoverage($permissions)) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::MissingPermissionCoverage,
                '/access/permissions',
                '/storage/Datastore.Audit',
            );
        }

        if (!$topology->isComplete()) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::IncompleteTopology,
                '/cluster/status',
                '/data',
            );
        }

        $startConfiguration = $this->readConfiguration($client, $issues);
        $statusSets = [];
        $observations = [];

        $nodes = $this->uniqueTopologyNodes($topology);
        if (count($nodes) > $this->maximumNodeFanout) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::NodeFanoutExceeded,
                '/cluster/status',
                '/data/*/name',
            );
            $nodes = [];
        }

        foreach ($nodes as $node) {
            try {
                $statusSet = $client->nodeBackupStorages($node);
            } catch (PveReadFailure) {
                $issues[] = new PveStorageIssue(
                    PveStorageIssueCode::NodeReadFailed,
                    sprintf('/nodes/%s/storage', $node),
                    '/data',
                    null,
                    $node,
                );
                continue;
            }

            if ($statusSet->node !== $node) {
                $issues[] = new PveStorageIssue(
                    PveStorageIssueCode::ConfigurationStatusConflict,
                    sprintf('/nodes/%s/storage', $node),
                    '/node',
                    null,
                    $node,
                );
            }

            array_push($issues, ...$statusSet->issues);
            $statusSets[$node] = $statusSet;
            $observedAt = $this->clock->now();
            foreach ($statusSet->statuses as $status) {
                $observations[] = PveNodeStorageObservation::fromStatus($status, $observedAt);
            }
        }

        $endConfiguration = $this->readConfiguration($client, $issues);
        $this->compareConfigurations($startConfiguration, $endConfiguration, $issues);

        if (null !== $startConfiguration) {
            $this->reconcile($startConfiguration, $statusSets, $issues);
        }

        return new PveStorageInventorySnapshot(
            $startConfiguration,
            $endConfiguration,
            $observations,
            $issues,
        );
    }

    /** @param list<PveStorageIssue> $issues */
    private function readConfiguration(PveReadClient $client, array &$issues): ?PveStorageConfigurationSet
    {
        try {
            $configuration = $client->storageConfigurations();
        } catch (PveReadFailure) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::ConfigurationReadFailed,
                '/storage',
                '/data',
            );
            return null;
        }

        array_push($issues, ...$configuration->issues);
        if (null === $configuration->globalDigest
            && !$this->hasIssue($configuration->issues, PveStorageIssueCode::MissingConfigurationDigest)
            && !$this->hasIssue($configuration->issues, PveStorageIssueCode::InconsistentConfigurationDigest)) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::MissingConfigurationDigest,
                '/storage',
                '/data/*/digest',
            );
        }

        return $configuration;
    }

    /** @param list<PveStorageIssue> $issues */
    private function compareConfigurations(
        ?PveStorageConfigurationSet $start,
        ?PveStorageConfigurationSet $end,
        array &$issues,
    ): void {
        if (null === $start || null === $end) {
            return;
        }

        if (null !== $start->globalDigest && null !== $end->globalDigest
            && $start->globalDigest !== $end->globalDigest) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::ConfigurationChanged,
                '/storage',
                '/data/*/digest',
            );
        }

        if (!$start->hasSameVisibleDefinitions($end)) {
            $issues[] = new PveStorageIssue(
                PveStorageIssueCode::VisibleConfigurationChanged,
                '/storage',
                '/data',
            );
        }
    }

    /**
     * @param array<string, PveNodeStorageStatusSet> $statusSets
     * @param list<PveStorageIssue>                  $issues
     */
    private function reconcile(
        PveStorageConfigurationSet $configuration,
        array $statusSets,
        array &$issues,
    ): void {
        foreach ($statusSets as $node => $statusSet) {
            $expected = [];
            foreach ($configuration->definitions as $definition) {
                if ($definition->isExpectedOn($node)) {
                    $expected[$definition->storageId] = $definition;
                }
            }

            $observed = [];
            foreach ($statusSet->statuses as $status) {
                $observed[$status->storageId] = $status;
            }

            foreach ($expected as $storageId => $definition) {
                if (!isset($observed[$storageId])) {
                    $issues[] = new PveStorageIssue(
                        PveStorageIssueCode::MissingExpectedObservation,
                        sprintf('/nodes/%s/storage', $node),
                        '/data',
                        $storageId,
                        $node,
                    );
                    continue;
                }

                $this->compareStatus($definition, $observed[$storageId], $issues);
            }

            foreach ($observed as $storageId => $status) {
                if (!isset($expected[$storageId])) {
                    $issues[] = new PveStorageIssue(
                        PveStorageIssueCode::UnexpectedObservation,
                        sprintf('/nodes/%s/storage', $node),
                        '/data',
                        $storageId,
                        $node,
                    );
                }
            }
        }
    }

    /** @param list<PveStorageIssue> $issues */
    private function compareStatus(
        PveStorageConfiguration $configuration,
        PveNodeStorageStatus $status,
        array &$issues,
    ): void {
        if ($configuration->storageType !== $status->storageType) {
            $issues[] = $this->conflict($status, 'type');
        }

        if (!$configuration->content->equals($status->content)) {
            $issues[] = $this->conflict($status, 'content');
        }

        if ($configuration->shared !== $status->shared) {
            $issues[] = $this->conflict($status, 'shared');
        }
    }

    private function conflict(PveNodeStorageStatus $status, string $field): PveStorageIssue
    {
        return new PveStorageIssue(
            PveStorageIssueCode::ConfigurationStatusConflict,
            sprintf('/nodes/%s/storage', $status->node),
            sprintf('/data/*/%s', $field),
            $status->storageId,
            $status->node,
        );
    }

    private function hasStoragePermissionCoverage(PvePermissionAssessment $permissions): bool
    {
        foreach ($permissions->missing as $missing) {
            if (PveRequiredPermission::DatastoreAudit === $missing->permission) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function uniqueTopologyNodes(PveClusterTopology $topology): array
    {
        $nodes = [];
        foreach ($topology->nodes as $node) {
            if ('' !== $node->name) {
                $nodes[$node->name] = true;
            }
        }

        $nodeNames = array_keys($nodes);
        usort($nodeNames, static fn (string $left, string $right): int => strcmp($left, $right));

        return $nodeNames;
    }

    /** @param list<PveStorageIssue> $issues */
    private function hasIssue(array $issues, PveStorageIssueCode $code): bool
    {
        foreach ($issues as $issue) {
            if ($code === $issue->code) {
                return true;
            }
        }

        return false;
    }
}
