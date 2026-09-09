<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pbs;

use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Inventory\Connection\InstallationIdentityValidator;
use InvalidArgumentException;

final readonly class PbsServerObservation
{
    public function __construct(
        public string $node,
        public PbsVersion $version,
        public ?PbsNodeStatus $status,
    ) {
        if (!InstallationIdentityValidator::isValid($node)
            || (3 !== $version->major && 4 !== $version->major)
            || (null !== $status && ($status->node !== $node
                || min(
                    $status->uptimeSeconds,
                    $status->memoryTotalBytes,
                    $status->memoryUsedBytes,
                    $status->rootTotalBytes,
                    $status->rootUsedBytes,
                    $status->rootAvailableBytes,
                ) < 0
                || $status->memoryUsedBytes > $status->memoryTotalBytes
                || $status->rootUsedBytes > $status->rootTotalBytes
                || $status->rootAvailableBytes > $status->rootTotalBytes))) {
            throw new InvalidArgumentException('The PBS server observation is invalid.');
        }
    }
}
