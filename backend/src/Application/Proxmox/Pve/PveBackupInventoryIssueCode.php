<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveBackupInventoryIssueCode: string
{
    case MissingRequiredField = 'missing_required_field';
    case InvalidField = 'invalid_field';
    case UnsupportedCapability = 'unsupported_capability';
    case DuplicateJob = 'duplicate_job';
    case IdentityMismatch = 'identity_mismatch';
    case ConflictingDuplicateTask = 'conflicting_duplicate_task';
    case TaskStreamReadFailed = 'task_stream_read_failed';
    case BackupJobReadFailed = 'backup_job_read_failed';
    case PageCapReached = 'page_cap_reached';
    case InvalidNode = 'invalid_node';
    case InconsistentTaskStatus = 'inconsistent_task_status';
}
