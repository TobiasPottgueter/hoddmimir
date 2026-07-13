<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum PolicyActivationBlocker: string
{
    case TargetUnconfigured = 'target_unconfigured';
    case ModeUnconfigured = 'mode_unconfigured';
    case CompressionUnconfigured = 'compression_unconfigured';
    case RetentionUnconfigured = 'retention_unconfigured';
    case PriorityUnconfigured = 'priority_unconfigured';
    case ThresholdsUnconfigured = 'thresholds_unconfigured';
    case ScheduleUnconfigured = 'schedule_unconfigured';
    case UnsupportedPveMajor = 'unsupported_pve_major';
    case RetentionIncompatible = 'retention_incompatible';
}
