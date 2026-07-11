<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pbs\PbsInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;

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

            $actualBinding = InstallationBinding::fromSnapshot($snapshot);
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
            if (
                null === $expectedBinding
                && InstallationBindingKind::PveCluster === $actualBinding->kind
                && !$snapshot->isComplete()
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
            if (null !== $expectedBinding && !$expectedBinding->matchesObservation($actualBinding)) {
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
            && (null === $binding || InstallationBindingKind::Pbs4Instance !== $binding->kind)
        ) {
            return [$target->endpoints[0]];
        }

        /** @var non-empty-list<EndpointScanReference> $endpoints */
        $endpoints = $target->endpoints;

        return $endpoints;
    }

    private function matchesProduct(
        ProxmoxProduct $product,
        PveInstallationSnapshot|PbsInstallationSnapshot $snapshot,
    ): bool {
        if (ProxmoxProduct::Pve === $product) {
            return $snapshot instanceof PveInstallationSnapshot;
        }

        return $snapshot instanceof PbsInstallationSnapshot;
    }
}
