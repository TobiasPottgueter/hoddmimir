<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

use App\Domain\Policy\FailureNotificationRecipients;
use App\Domain\Shared\UInt64Decimal;
use InvalidArgumentException;

/** Shared decisions for planning, manual queueing, claiming and final submission. */
final readonly class BackupStartRules
{
    /** @return array<string, bool> */
    public static function resourceChecks(BackupResourceEvidence $evidence): array
    {
        return [
            'connection' => $evidence->connectionEnabled,
            'cluster' => $evidence->clusterActive,
            'guest' => self::guestEligible($evidence->guestActive, $evidence->guestTemplate),
            'node' => self::nodeEligible($evidence->nodeActive, $evidence->nodeOnline),
            'policy' => $evidence->policyEnabled,
            'target' => $evidence->targetEnabled,
            'storage_enabled' => self::storageEnabled($evidence->storageSupportsBackup, $evidence->storageDisabled, $evidence->storageActive, $evidence->nodeStorageEnabled),
            'storage_active' => $evidence->nodeStorageActive,
            'executor' => $evidence->executorAuthorized,
            'target_node' => $evidence->targetNodeAllowed,
            'selection' => $evidence->selectionIncluded,
            'exclusion_absent' => !$evidence->selectionExcluded,
            'active_request_absent' => $evidence->activeRequestAbsent,
            'capacity_present' => $evidence->capacityEvidencePresent,
        ];
    }

    public static function resourcesEligible(BackupResourceEvidence $evidence): bool
    {
        return !\in_array(false, self::resourceChecks($evidence), true);
    }

    public static function guestEligible(bool $active, ?bool $template): bool
    {
        return $active && false === $template;
    }

    public static function nodeEligible(bool $active, bool $online): bool
    {
        return $active && $online;
    }

    public static function storageEnabled(bool $supportsBackup, ?bool $disabled, bool $active, bool $nodeEnabled): bool
    {
        return $supportsBackup && false === $disabled && $active && $nodeEnabled;
    }

    public static function pbsTargetEligible(bool $connectionEnabled, bool $writesAllowed, bool $active, bool $filesystemCapacity, bool $mappingValid, bool $capacityPresent): bool
    {
        return $connectionEnabled && $writesAllowed && $active && $filesystemCapacity && $mappingValid && $capacityPresent;
    }

    public static function snapshotMatches(string $node, int $placement, int $policy, int $target, string $currentNode, int $currentPlacement, int $currentPolicy, int $currentTarget): bool
    {
        return hash_equals($node, $currentNode) && $placement === $currentPlacement && $policy === $currentPolicy && $target === $currentTarget;
    }

    public static function notificationRecipientsConfigured(mixed $recipients): bool
    {
        if (!is_array($recipients) || !array_is_list($recipients) || [] === $recipients) return false;
        foreach ($recipients as $recipient) if (!is_string($recipient)) return false;
        try {
            /** @var list<string> $recipients */
            new FailureNotificationRecipients($recipients);
        } catch (InvalidArgumentException) {
            return false;
        }
        return true;
    }

    public static function effectiveCapacity(?UInt64Decimal $pve, bool $pbsTarget, ?UInt64Decimal $pbs): ?UInt64Decimal
    {
        if (!$pbsTarget || null === $pve) return $pve;
        if (null === $pbs) return null;
        return $pbs->lessThanOrEqual($pve) ? $pbs : $pve;
    }

    public static function capacityAvailable(?UInt64Decimal $available, ?UInt64Decimal $minimum, ?UInt64Decimal $reserved, ?UInt64Decimal $newReservation): bool
    {
        if (null === $available || null === $minimum || null === $reserved || null === $newReservation) return false;
        // Subtract successively: adding demands could overflow even unsigned 64-bit arithmetic.
        foreach ([$minimum, $reserved, $newReservation] as $demand) {
            if (!$demand->lessThanOrEqual($available)) return false;
            $available = $available->minus($demand);
        }
        return true;
    }

    public static function slotAvailable(?int $limit, ?int $used, int $configuredLimit, bool $alreadyReserved): bool
    {
        if (null === $limit || null === $used || $configuredLimit < 1 || $limit !== $configuredLimit || $used < 0) return false;
        return $alreadyReserved ? $used >= 1 && $used <= $limit : $used < $limit;
    }
}
