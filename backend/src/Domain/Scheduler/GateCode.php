<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

enum GateCode: string
{
    case ConnectionEnabled = 'connection_enabled';
    case ClusterEnabled = 'cluster_enabled';
    case NodeEnabled = 'node_enabled';
    case GuestEnabled = 'guest_enabled';
    case PolicyEnabled = 'policy_enabled';
    case TargetEnabled = 'target_enabled';
    case ExplicitExclusionAbsent = 'explicit_exclusion_absent';
    case GuestActive = 'guest_active';
    case PlacementPresent = 'placement_present';
    case PlacementFresh = 'placement_fresh';
    case ActiveRequestAbsent = 'active_request_absent';
    case TargetNodeAllowed = 'target_node_allowed';
    case TargetStorageEnabled = 'target_storage_enabled';
    case TargetStorageActive = 'target_storage_active';
    case ExecutorAuthorized = 'executor_authorized';
    case CapacityFresh = 'capacity_fresh';
    case MinimumFreeSpace = 'minimum_free_space';
    case NodeConcurrency = 'node_concurrency';
    case TargetConcurrency = 'target_concurrency';
    case PbsMappingValid = 'pbs_mapping_valid';
}
