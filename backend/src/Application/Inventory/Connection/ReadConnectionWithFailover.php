<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventorySnapshot;

final readonly class ReadConnectionWithFailover
{
    public function __construct(private EndpointInstallationReader $reader)
    {
    }

    public function read(
        ConnectionScanTarget $target,
        ?InstallationBinding $expectedBinding,
        ConnectionReadCheckpoint $checkpoint,
        ?EndpointReadAttemptSink $attemptSink = null,
    ): ConnectionInstallationRead {
        if ([] === $target->endpoints) {
            throw ConnectionReadFailure::noEndpoints();
        }
        if (null !== $expectedBinding && $target->product !== $expectedBinding->product) {
            throw ConnectionReadFailure::invalidBinding();
        }

        $endpoints = $this->eligibleEndpoints($target, $expectedBinding);
        $lastEndpoint = $endpoints[0]->endpointId;
        $lastFailure = EndpointReadFailureCode::RootUnusable;

        $endpointCount = count($endpoints);
        foreach ($endpoints as $index => $endpoint) {
            $lastEndpoint = $endpoint->endpointId;
            $attempt = null;
            $checkpoint->checkpoint();
            if (null !== $attemptSink) {
                $attempt = $attemptSink->start($endpoint->endpointId, $index + 1);
            }
            try {
                $snapshot = $this->reader->read(
                    $target->connectionId,
                    $endpoint->endpointId,
                    $target->expectedRevision,
                    $target->product,
                    $checkpoint,
                );
            } catch (EndpointReadFailure $failure) {
                $outcome = $failure->failureCode->allowsEndpointFailover() && $index + 1 < $endpointCount
                    ? EndpointReadAttemptOutcome::Failover
                    : EndpointReadAttemptOutcome::Terminal;
                $this->finishAttempt($attemptSink, $checkpoint, $attempt, $outcome, $failure->failureCode);
                if (!$failure->failureCode->allowsEndpointFailover()) {
                    throw ConnectionReadFailure::terminal($endpoint->endpointId, $failure->failureCode);
                }
                $lastFailure = $failure->failureCode;
                continue;
            }

            if (!$this->matchesProduct($target->product, $snapshot)) {
                $this->finishAttempt(
                    $attemptSink,
                    $checkpoint,
                    $attempt,
                    $this->rejectionOutcome($index, $endpointCount),
                    EndpointReadFailureCode::RootUnusable,
                );
                $lastFailure = EndpointReadFailureCode::RootUnusable;
                continue;
            }

            $actualBinding = InstallationBinding::fromSnapshot($snapshot, $endpoint->endpointId);
            if (null === $actualBinding) {
                $this->finishAttempt(
                    $attemptSink,
                    $checkpoint,
                    $attempt,
                    $this->rejectionOutcome($index, $endpointCount),
                    EndpointReadFailureCode::RootUnusable,
                );
                $lastFailure = EndpointReadFailureCode::RootUnusable;
                continue;
            }
            if (ProxmoxProduct::Pbs === $target->product
                && InstallationBindingKind::PbsLegacyNode === $actualBinding->kind
                && (null === $expectedBinding
                    || InstallationBindingKind::PbsLegacyNode === $expectedBinding->kind)
                && count($target->endpoints) > 1) {
                $this->finishAttempt(
                    $attemptSink,
                    $checkpoint,
                    $attempt,
                    EndpointReadAttemptOutcome::Terminal,
                    EndpointReadFailureCode::RootUnusable,
                );
                throw ConnectionReadFailure::invalidEndpointConfiguration();
            }
            if (
                null === $expectedBinding
                && InstallationBindingKind::PveCluster === $actualBinding->kind
                && !$this->pveCoreIsComplete($snapshot)
            ) {
                $this->finishAttempt(
                    $attemptSink,
                    $checkpoint,
                    $attempt,
                    $this->rejectionOutcome($index, $endpointCount),
                    EndpointReadFailureCode::RootUnusable,
                );
                $lastFailure = EndpointReadFailureCode::RootUnusable;
                continue;
            }
            if (null !== $expectedBinding && !$this->matchesExpectedBinding(
                $expectedBinding,
                $actualBinding,
                $snapshot,
                $endpoint->endpointId,
            )) {
                $this->finishAttempt(
                    $attemptSink,
                    $checkpoint,
                    $attempt,
                    $this->rejectionOutcome($index, $endpointCount),
                    EndpointReadFailureCode::WrongIdentity,
                );
                $lastFailure = EndpointReadFailureCode::WrongIdentity;
                continue;
            }

            $this->finishAttempt(
                $attemptSink,
                $checkpoint,
                $attempt,
                EndpointReadAttemptOutcome::Selected,
                null,
            );

            return new ConnectionInstallationRead(
                $target->connectionId,
                $target->expectedRevision,
                $endpoint->endpointId,
                $actualBinding,
                $snapshot,
            );
        }

        throw ConnectionReadFailure::exhausted($lastEndpoint, $lastFailure);
    }

    private function finishAttempt(
        ?EndpointReadAttemptSink $sink,
        ConnectionReadCheckpoint $checkpoint,
        ?EndpointReadAttemptStarted $attempt,
        EndpointReadAttemptOutcome $outcome,
        ?EndpointReadFailureCode $failureCode,
    ): void {
        if (null === $sink || null === $attempt) {
            $checkpoint->checkpoint();
            return;
        }
        $checkpoint->checkpoint();
        $sink->finish($attempt, $outcome, $failureCode);
        $checkpoint->checkpoint();
    }

    private function rejectionOutcome(int $index, int $endpointCount): EndpointReadAttemptOutcome
    {
        return $index + 1 < $endpointCount
            ? EndpointReadAttemptOutcome::Failover
            : EndpointReadAttemptOutcome::Terminal;
    }

    /** @return non-empty-list<EndpointScanReference> */
    private function eligibleEndpoints(
        ConnectionScanTarget $target,
        ?InstallationBinding $binding,
    ): array {
        if (
            ProxmoxProduct::Pbs === $target->product
            && null === $binding
        ) {
            return [$target->endpoints[0]];
        }

        if (ProxmoxProduct::Pbs === $target->product
            && InstallationBindingKind::PbsLegacyNode === $binding->kind) {
            foreach ($target->endpoints as $endpoint) {
                if ($endpoint->endpointId->bytes === $binding->legacyEndpointId?->bytes) {
                    return [$endpoint];
                }
            }

            throw ConnectionReadFailure::invalidEndpointConfiguration();
        }

        /** @var non-empty-list<EndpointScanReference> $endpoints */
        $endpoints = $target->endpoints;

        return $endpoints;
    }

    private function matchesExpectedBinding(
        InstallationBinding $expected,
        InstallationBinding $actual,
        PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot $snapshot,
        EndpointId $endpointId,
    ): bool {
        if ($expected->matchesObservation($actual)) {
            return true;
        }

        return InstallationBindingKind::PbsLegacyNode === $expected->kind
            && InstallationBindingKind::PbsInstance === $actual->kind
            && $snapshot instanceof PbsInstallationSnapshot
            && $snapshot->scope->installationWide
            && $snapshot->isComplete()
            && $expected->legacyEndpointId?->bytes === $endpointId->bytes
            && hash_equals($expected->identity, $snapshot->node);
    }

    private function matchesProduct(
        ProxmoxProduct $product,
        PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot $snapshot,
    ): bool {
        if (ProxmoxProduct::Pve === $product) {
            return $snapshot instanceof PveInstallationSnapshot || $snapshot instanceof PveInventorySnapshot;
        }

        return $snapshot instanceof PbsInstallationSnapshot;
    }

    private function pveCoreIsComplete(
        PveInstallationSnapshot|PveInventorySnapshot|PbsInstallationSnapshot $snapshot,
    ): bool {
        if ($snapshot instanceof PveInventorySnapshot) {
            return $snapshot->core->isComplete();
        }

        return $snapshot instanceof PveInstallationSnapshot && $snapshot->isComplete();
    }
}
