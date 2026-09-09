<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveGuestResource;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInventoryIssue;
use App\Application\Proxmox\Pve\PveInventoryIssueCode;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageResource;

final readonly class PveClusterResourcesReader
{
    public function read(mixed $data): PveResourceInventory
    {
        if (!is_array($data) || !array_is_list($data)) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidResponse);
        }

        $nodes = [];
        $guests = [];
        $storages = [];
        $issues = [];
        $identities = [];

        foreach ($data as $index => $row) {
            if ($row instanceof \stdClass) {
                $row = get_object_vars($row);
            }

            if (!is_array($row)) {
                $this->missing($issues, 'unknown', $index, 'row');
                continue;
            }

            $type = $this->nonEmptyString($row['type'] ?? null);
            if (null === $type) {
                $this->missing($issues, 'unknown', $index, 'type');
                continue;
            }

            if (null === $this->nonEmptyString($row['id'] ?? null)) {
                $this->missing($issues, $type, $index, 'id');
                continue;
            }

            if ('node' === $type) {
                $node = $this->readNode($row, $index, $issues);
                if (null !== $node && $this->acceptIdentity('node:'.$node->name, 'node', $index, $identities, $issues)) {
                    $nodes[] = $node;
                }
                continue;
            }

            if ('qemu' === $type || 'lxc' === $type) {
                $guest = $this->readGuest($row, $index, $type, $issues);
                if (null !== $guest && $this->acceptIdentity('guest:'.$guest->identity(), $type, $index, $identities, $issues)) {
                    $guests[] = $guest;
                }
                continue;
            }

            if ('storage' === $type) {
                $storage = $this->readStorage($row, $index, $issues);
                if (null !== $storage && $this->acceptIdentity('storage:'.$storage->identity(), 'storage', $index, $identities, $issues)) {
                    $storages[] = $storage;
                }
            }
        }

        return new PveResourceInventory($nodes, $guests, $storages, $issues);
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function readNode(array $row, int $index, array &$issues): ?PveNodeResource
    {
        $name = $this->nonEmptyString($row['node'] ?? null);
        if (null === $name) {
            $this->missing($issues, 'node', $index, 'node');
            return null;
        }

        return new PveNodeResource($name, $this->optionalString($row['status'] ?? null));
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function readGuest(array $row, int $index, string $type, array &$issues): ?PveGuestResource
    {
        $vmid = $row['vmid'] ?? null;
        if (!is_int($vmid) || $vmid < 1) {
            $this->missing($issues, $type, $index, 'vmid');
            return null;
        }

        $node = $this->nonEmptyString($row['node'] ?? null);
        if (null === $node) {
            $this->missing($issues, $type, $index, 'node');
            return null;
        }

        return new PveGuestResource(
            'qemu' === $type ? PveGuestType::Qemu : PveGuestType::Lxc,
            $vmid,
            $node,
            $this->optionalString($row['name'] ?? null),
            $this->optionalBool($row['template'] ?? null),
            $this->optionalString($row['status'] ?? null),
            $this->optionalNonNegativeInt($row['diskwrite'] ?? null),
            $this->optionalNonNegativeInt($row['maxdisk'] ?? null),
        );
    }

    /**
     * @param array<array-key, mixed> $row
     * @param list<PveInventoryIssue> $issues
     */
    private function readStorage(array $row, int $index, array &$issues): ?PveStorageResource
    {
        $storageName = $this->nonEmptyString($row['storage'] ?? null);
        if (null === $storageName) {
            $this->missing($issues, 'storage', $index, 'storage');
            return null;
        }

        $node = $this->nonEmptyString($row['node'] ?? null);
        if (null === $node) {
            $this->missing($issues, 'storage', $index, 'node');
            return null;
        }

        $content = $this->nonEmptyString($row['content'] ?? null);
        if (null === $content) {
            $this->missing($issues, 'storage', $index, 'content');
            return null;
        }

        return new PveStorageResource(
            $storageName,
            $node,
            $this->optionalString($row['status'] ?? null),
            $content,
            $this->optionalNonNegativeInt($row['maxdisk'] ?? null),
            $this->optionalNonNegativeInt($row['disk'] ?? null),
            null,
        );
    }

    /**
     * @param array<string, true>      $identities
     * @param list<PveInventoryIssue> $issues
     */
    private function acceptIdentity(
        string $identity,
        string $resourceType,
        int $index,
        array &$identities,
        array &$issues,
    ): bool {
        if (isset($identities[$identity])) {
            $issues[] = new PveInventoryIssue(
                PveInventoryIssueCode::DuplicateResource,
                $resourceType,
                $index,
                sprintf('/data/%d', $index),
            );
            return false;
        }

        $identities[$identity] = true;
        return true;
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

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
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
