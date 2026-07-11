<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveInventoryIssue;
use App\Application\Proxmox\Pve\PveInventoryIssueCode;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;

final readonly class PveClusterStatusReader
{
    public function read(mixed $data): PveClusterTopology
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $clusterRecordSeen = false;
        $clusterRecordIndex = -1;
        $clusterName = null;
        $declaredNodeCount = null;
        $configurationVersion = null;
        $quorate = null;
        $nodes = [];
        $nodeSourceIndexes = [];
        $issues = [];
        $seenNodes = [];

        foreach ($data as $index => $row) {
            if ($row instanceof \stdClass) {
                $row = get_object_vars($row);
            }

            if (!is_array($row)) {
                $this->missing($issues, 'unknown', $index, 'row');
                continue;
            }

            $type = $this->requiredNonEmptyString($row, 'type', 'unknown', $index, $issues);
            if (null === $type) {
                continue;
            }

            if ('cluster' === $type) {
                if ($clusterRecordSeen) {
                    $issues[] = new PveInventoryIssue(
                        PveInventoryIssueCode::DuplicateClusterRecord,
                        'cluster',
                        $index,
                        sprintf('/data/%d/type', $index),
                    );
                    continue;
                }

                $clusterRecordSeen = true;
                $clusterRecordIndex = $index;
                $id = $this->requiredNonEmptyString($row, 'id', 'cluster', $index, $issues);
                $clusterName = $this->requiredNonEmptyString($row, 'name', 'cluster', $index, $issues);
                if (null !== $id && 'cluster' !== $id) {
                    $this->invalid($issues, 'cluster', $index, 'id');
                }
                $declaredNodeCount = $this->requiredPositiveInt($row, 'nodes', 'cluster', $index, $issues);
                $configurationVersion = $this->requiredPositiveInt(
                    $row,
                    'version',
                    'cluster',
                    $index,
                    $issues,
                );
                $quorate = $this->requiredBool($row, 'quorate', 'cluster', $index, $issues);
                continue;
            }

            $id = $this->requiredNonEmptyString($row, 'id', $type, $index, $issues);
            $name = $this->requiredNonEmptyString($row, 'name', $type, $index, $issues);
            if (null === $id || null === $name) {
                continue;
            }

            if ('node' !== $type) {
                continue;
            }

            if (isset($seenNodes[$name])) {
                $issues[] = new PveInventoryIssue(
                    PveInventoryIssueCode::DuplicateResource,
                    'node',
                    $index,
                    sprintf('/data/%d/name', $index),
                );
                continue;
            }

            $seenNodes[$name] = true;
            $nodeSourceIndexes[] = $index;
            $nodes[] = new PveClusterNode(
                $name,
                $this->optionalBool($row['online'] ?? null),
                $this->optionalNonNegativeInt($row['nodeid'] ?? null),
                $this->optionalBool($row['local'] ?? null),
            );
        }

        if ($clusterRecordSeen) {
            if ([] === $nodes) {
                $this->invalid($issues, 'topology', -1, null);
            }
            if (null !== $declaredNodeCount && $declaredNodeCount !== count($nodes)) {
                $this->invalid($issues, 'cluster', $clusterRecordIndex, 'nodes');
            }
            if (false === $quorate) {
                $this->invalid($issues, 'cluster', $clusterRecordIndex, 'quorate');
            }
        } elseif (1 !== count($nodes)) {
            $this->invalid($issues, 'topology', -1, null);
        } else {
            $nodeIndex = $nodeSourceIndexes[0];
            if (true !== $nodes[0]->local) {
                $this->invalid($issues, 'node', $nodeIndex, 'local');
            }
            if (0 !== $nodes[0]->localNodeId) {
                $this->invalid($issues, 'node', $nodeIndex, 'nodeid');
            }
        }

        return new PveClusterTopology(
            $clusterRecordSeen ? PveClusterMode::Clustered : PveClusterMode::Standalone,
            $clusterName,
            $declaredNodeCount,
            $configurationVersion,
            $quorate,
            $nodes,
            $issues,
        );
    }

    /** @param list<PveInventoryIssue> $issues */
    private function missing(array &$issues, string $resourceType, int $index, string $field): void
    {
        $issues[] = new PveInventoryIssue(
            PveInventoryIssueCode::MissingRequiredField,
            $resourceType,
            $index,
            sprintf('/data/%d/%s', $index, $field),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function requiredNonEmptyString(
        array $row,
        string $field,
        string $resourceType,
        int $index,
        array &$issues,
    ): ?string {
        if (!array_key_exists($field, $row)) {
            $this->missing($issues, $resourceType, $index, $field);
            return null;
        }

        $value = $row[$field];
        if (!is_string($value) || '' === $value) {
            $this->invalid($issues, $resourceType, $index, $field);
            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function requiredPositiveInt(
        array $row,
        string $field,
        string $resourceType,
        int $index,
        array &$issues,
    ): ?int {
        if (!array_key_exists($field, $row)) {
            $this->missing($issues, $resourceType, $index, $field);
            return null;
        }

        $value = $row[$field];
        if (!is_int($value) || $value < 1) {
            $this->invalid($issues, $resourceType, $index, $field);
            return null;
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function requiredBool(
        array $row,
        string $field,
        string $resourceType,
        int $index,
        array &$issues,
    ): ?bool {
        if (!array_key_exists($field, $row)) {
            $this->missing($issues, $resourceType, $index, $field);
            return null;
        }

        $value = $this->optionalBool($row[$field]);
        if (null === $value) {
            $this->invalid($issues, $resourceType, $index, $field);
            return null;
        }

        return $value;
    }

    /** @param list<PveInventoryIssue> $issues */
    private function invalid(array &$issues, string $resourceType, int $index, ?string $field): void
    {
        $issues[] = new PveInventoryIssue(
            PveInventoryIssueCode::InvalidTopology,
            $resourceType,
            $index,
            null === $field ? '/data' : sprintf('/data/%d/%s', $index, $field),
        );
    }

    private function optionalBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return 0 === $value || 1 === $value ? (bool) $value : null;
    }

    private function optionalNonNegativeInt(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
