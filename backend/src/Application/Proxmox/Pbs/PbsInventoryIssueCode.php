<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsInventoryIssueCode: string
{
    case MissingSystemStatusPermission = 'missing_system_status_permission';
    case MissingDatastorePermission = 'missing_datastore_permission';
    case MissingDatastorePropagation = 'missing_datastore_propagation';
    case NodeStatusReadFailed = 'node_status_read_failed';
    case IdentityReadFailed = 'identity_read_failed';
    case ConfigurationReadFailed = 'configuration_read_failed';
    case DatastoreListReadFailed = 'datastore_list_read_failed';
    case DatastoreStatusReadFailed = 'datastore_status_read_failed';
    case ConfigurationChanged = 'configuration_changed';
    case MissingDatastoreConfiguration = 'missing_datastore_configuration';
    case MissingDatastore = 'missing_datastore';
    case UnexpectedDatastore = 'unexpected_datastore';
    case BackendMismatch = 'backend_mismatch';
    case UnavailableDatastore = 'unavailable_datastore';
}
