<?php

declare(strict_types=1);

namespace App\Application\Configuration\Policy;

enum PolicyActivationBlockerCode: string
{
    case PveEvidenceMissing = 'pve_evidence_missing';
    case PveEvidenceStale = 'pve_evidence_stale';
    case PveEvidenceFuture = 'pve_evidence_future';
    case UnsupportedPveMajor = 'unsupported_pve_major';
    case TargetEvidenceMissing = 'target_evidence_missing';
    case TargetEvidenceStale = 'target_evidence_stale';
    case TargetEvidenceFuture = 'target_evidence_future';
    case TargetDisabled = 'target_disabled';
    case ExecutorEvidenceMissing = 'executor_evidence_missing';
    case ExecutorEvidenceStale = 'executor_evidence_stale';
    case ExecutorEvidenceFuture = 'executor_evidence_future';
    case ExecutorUnauthorized = 'executor_unauthorized';
    case RetentionExecutionForbiddenForPbsTarget = 'retention_execution_forbidden_for_pbs_target';
    case TargetUnconfigured = 'target_unconfigured';
    case ModeUnconfigured = 'mode_unconfigured';
    case CompressionUnconfigured = 'compression_unconfigured';
    case RetentionUnconfigured = 'retention_unconfigured';
    case PriorityUnconfigured = 'priority_unconfigured';
    case ThresholdsUnconfigured = 'thresholds_unconfigured';
    case ScheduleUnconfigured = 'schedule_unconfigured';
    case RetentionIncompatible = 'retention_incompatible';
}
