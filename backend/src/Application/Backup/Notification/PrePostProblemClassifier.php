<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use App\Application\Scheduler\Shadow\OrderedShadowGate;
use App\Domain\Backup\BackupProblemCode;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;

final readonly class PrePostProblemClassifier
{
    /** @param list<OrderedShadowGate> $gates */
    public function scheduler(array $gates, bool $backupIsDue): ?PrePostProblem
    {
        if (!$backupIsDue) {
            return null;
        }

        $failures = [];
        foreach ($gates as $gate) {
            if (!$gate->result->passed) {
                $failures[$gate->result->code->value] = $gate;
            }
        }

        // A due candidate that cannot proceed only because another request or
        // a finite slot owns the resource is healthy backpressure. Do not let
        // incidental stale/capacity evidence turn that occurrence into noise.
        foreach ([GateCode::ActiveRequestAbsent, GateCode::NodeConcurrency, GateCode::TargetConcurrency] as $silent) {
            if (isset($failures[$silent->value])) {
                return null;
            }
        }

        foreach ([GateCode::PlacementPresent, GateCode::TargetNodeAllowed, GateCode::NodeEnabled] as $code) {
            if (isset($failures[$code->value])) {
                return $this->gate(BackupProblemCode::PlacementChanged, $failures[$code->value]);
            }
        }
        if (isset($failures[GateCode::ExecutorAuthorized->value])) {
            return $this->gate(BackupProblemCode::PermissionBlocked, $failures[GateCode::ExecutorAuthorized->value]);
        }
        foreach ([GateCode::InventoryFresh, GateCode::PlacementFresh, GateCode::CapacityFresh, GateCode::ExecutorAuthorizationFresh] as $code) {
            if (isset($failures[$code->value])) {
                return $this->gate(BackupProblemCode::EvidenceStale, $failures[$code->value]);
            }
        }
        if (isset($failures[GateCode::MinimumFreeSpace->value])) {
            $failure = $failures[GateCode::MinimumFreeSpace->value];
            $code = GateDetailCode::InsufficientFreeSpace === $failure->result->detailCode
                ? BackupProblemCode::CapacityBlocked
                : BackupProblemCode::EvidenceStale;

            return $this->gate($code, $failure);
        }
        foreach ([
            GateCode::PolicyFailureNotificationConfigured,
            GateCode::TargetStorageEnabled,
            GateCode::TargetStorageActive,
            GateCode::PbsMappingValid,
            GateCode::PolicyRetentionCompatible,
        ] as $code) {
            if (isset($failures[$code->value])) {
                return $this->gate(BackupProblemCode::ConfigurationBlocked, $failures[$code->value]);
            }
        }

        // Explicit enable/selection choices, duplicate suppression and
        // concurrency are planning/backpressure states, not incidents.
        return null;
    }

    public function queue(string $detailCode): ?PrePostProblem
    {
        return match ($detailCode) {
            'capacity_unavailable' => new PrePostProblem(BackupProblemCode::CapacityBlocked, $detailCode),
            'executor_unauthorized' => new PrePostProblem(BackupProblemCode::PermissionBlocked, $detailCode),
            'snapshot_revision_changed', 'placement_changed' => new PrePostProblem(BackupProblemCode::PlacementChanged, $detailCode),
            'revalidation_evidence_missing', 'cluster_observed_at_stale', 'guest_observed_at_stale',
            'node_observed_at_stale', 'storage_observed_at_stale', 'current_placement_observed_at_stale',
            'capacity_observed_at_stale', 'executor_observed_at_stale', 'cluster_seen_stale',
            'guest_seen_stale', 'placement_seen_stale', 'node_seen_stale', 'storage_seen_stale',
            'capacity_seen_stale', 'executor_seen_stale' => new PrePostProblem(BackupProblemCode::EvidenceStale, $detailCode),
            'expected_size_missing' => new PrePostProblem(BackupProblemCode::EvidenceStale, $detailCode),
            'eligibility_changed', 'policy_snapshot_invalid', 'pve_evidence_invalid',
            'pbs_evidence_invalid', 'retention_incompatible',
            'target_parallel_limit_missing', 'claim_resources_inconsistent',
            'failure_notification_recipients_unconfigured' => new PrePostProblem(BackupProblemCode::ConfigurationBlocked, $detailCode),
            'node_slot_unavailable', 'target_slot_unavailable', 'active_request_exists',
            'execution_disabled', 'cooldown', 'cancel_requested' => null,
            default => throw new \InvalidArgumentException('An unmapped pre-POST blocker cannot be classified.'),
        };
    }

    private function gate(BackupProblemCode $problemCode, OrderedShadowGate $gate): PrePostProblem
    {
        $detail = $gate->result->code->value.'.'.$gate->result->detailCode->value;

        return new PrePostProblem($problemCode, $detail);
    }
}
