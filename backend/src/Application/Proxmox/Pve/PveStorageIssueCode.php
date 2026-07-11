<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveStorageIssueCode: string
{
    case MissingRequiredField = 'missing_required_field';
    case InvalidField = 'invalid_field';
    case DuplicateStorage = 'duplicate_storage';
    case MissingConfigurationDigest = 'missing_configuration_digest';
    case InconsistentConfigurationDigest = 'inconsistent_configuration_digest';
    case ConfigurationReadFailed = 'configuration_read_failed';
    case ConfigurationChanged = 'configuration_changed';
    case VisibleConfigurationChanged = 'visible_configuration_changed';
    case MissingPermissionCoverage = 'missing_permission_coverage';
    case IncompleteTopology = 'incomplete_topology';
    case NodeReadFailed = 'node_read_failed';
    case MissingExpectedObservation = 'missing_expected_observation';
    case UnexpectedObservation = 'unexpected_observation';
    case ConfigurationStatusConflict = 'configuration_status_conflict';
    case InvalidCapacity = 'invalid_capacity';
}
