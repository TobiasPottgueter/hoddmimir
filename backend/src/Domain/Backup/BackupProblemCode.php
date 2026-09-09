<?php

declare(strict_types=1);

namespace App\Domain\Backup;

enum BackupProblemCode: string
{
    case SubmissionRejected = 'submission_rejected';
    case TaskFailed = 'task_failed';
    case CapacityBlocked = 'capacity_blocked';
    case PermissionBlocked = 'permission_blocked';
    case EvidenceStale = 'evidence_stale';
    case PlacementChanged = 'placement_changed';
    case ConfigurationBlocked = 'configuration_blocked';
    case ReconciliationRequired = 'reconciliation_required';
    case MonitoringUnknown = 'monitoring_unknown';
    case CancelDispatchUnknown = 'cancel_dispatch_unknown';
}
