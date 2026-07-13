<?php

declare(strict_types=1);

namespace App\Domain\Target;

enum TargetDraftBlockerCode: string
{
    case TargetDisabled = 'target_disabled';
    case MinimumFreeBytesUnconfigured = 'minimum_free_bytes_unconfigured';
    case ConcurrencyPolicyUnconfigured = 'concurrency_policy_unconfigured';
}
