<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

enum BackupTargetBlockerCode: string
{
    case FreshnessPolicyUnconfigured = 'freshness_policy_unconfigured';
    case ConnectionDisabled = 'connection_disabled';
    case ConnectionNotPve = 'connection_not_pve';
    case ClusterArchived = 'cluster_archived';
    case StorageArchived = 'storage_archived';
    case StorageDisabled = 'storage_disabled';
    case BackupContentUnsupported = 'backup_content_unsupported';
    case NoActiveNode = 'no_active_node';
    case NoUsableNode = 'no_usable_node';
    case StorageNotConfiguredOnNode = 'storage_not_configured_on_node';
    case NodeStateMissing = 'node_state_missing';
    case NodeOffline = 'node_offline';
    case NodeStorageDisabled = 'node_storage_disabled';
    case NodeStorageInactive = 'node_storage_inactive';
    case CapacityUnavailable = 'capacity_unavailable';
    case CapacityInvalid = 'capacity_invalid';
    case PbsMappingMissing = 'pbs_mapping_missing';
    case PbsEndpointUnresolved = 'pbs_endpoint_unresolved';
    case PbsEndpointAmbiguous = 'pbs_endpoint_ambiguous';
    case PbsConnectionDisabled = 'pbs_connection_disabled';
    case PbsServerMissing = 'pbs_server_missing';
    case PbsDatastoreMissing = 'pbs_datastore_missing';
    case PbsDatastoreArchived = 'pbs_datastore_archived';
    case PbsDatastoreReadOnly = 'pbs_datastore_read_only';
    case PbsNamespaceMissing = 'pbs_namespace_missing';
    case PbsNamespaceArchived = 'pbs_namespace_archived';
    case PbsCapacityMissing = 'pbs_capacity_missing';
    case PbsRemoteCapacityUnproven = 'pbs_remote_capacity_unproven';
}
