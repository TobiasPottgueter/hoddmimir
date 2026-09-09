<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsContentLimits
{
    public function __construct(
        public int $maximumDatastores = 128,
        public int $maximumNamespacesPerDatastore = 1024,
        public int $maximumSnapshotsPerNamespace = 65536,
        public int $maximumTotalSnapshots = 262144,
        public int $namespaceBodyBytes = 8388608,
        public int $snapshotBodyBytes = 67108864,
    ) {
        if ($maximumDatastores < 1 || $maximumDatastores > 1024
            || $maximumNamespacesPerDatastore < 1 || $maximumNamespacesPerDatastore > 65536
            || $maximumSnapshotsPerNamespace < 1 || $maximumSnapshotsPerNamespace > 1048576
            || $maximumTotalSnapshots < $maximumSnapshotsPerNamespace || $maximumTotalSnapshots > 4194304
            || $namespaceBodyBytes < 65536 || $namespaceBodyBytes > 67108864
            || $snapshotBodyBytes < 1048576 || $snapshotBodyBytes > 268435456) {
            throw new InvalidArgumentException('The PBS content inventory limits are invalid.');
        }
    }
}
