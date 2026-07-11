<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveInventoryIssueCode: string
{
    case MissingRequiredField = 'missing_required_field';
    case DuplicateResource = 'duplicate_resource';
    case DuplicateClusterRecord = 'duplicate_cluster_record';
    case InvalidTopology = 'invalid_topology';
}
