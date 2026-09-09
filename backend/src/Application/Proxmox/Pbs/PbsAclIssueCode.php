<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsAclIssueCode: string
{
    case MissingSystemTaskAudit = 'missing_system_task_audit';
    case MissingDatastoreAuditPropagation = 'missing_datastore_audit_propagation';
    case MissingRemoteAuditPropagation = 'missing_remote_audit_propagation';
}
