<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsJobId;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsJobObservation;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsSyncDirection;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsUpid;
use App\Infrastructure\Validation\ObjectPropertyInspector;
use InvalidArgumentException;
use ValueError;

final readonly class PbsJobListReader
{
    public function read(PbsApiEnvelope $envelope, PbsJobKind $kind, int $maximumJobs): PbsJobListSnapshot
    {
        if (!is_array($envelope->data) || count($envelope->data) > $maximumJobs
            || !is_string($envelope->digest)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $jobs = [];
        foreach ($envelope->data as $row) {
            if (!$row instanceof \stdClass) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            try {
                $id = new PbsJobId($this->requiredString($row, 'id'));
                $store = new PbsDatastoreId($this->requiredString($row, 'store'));
                $namespace = $this->namespace($row, 'ns');
                $schedule = $this->optionalString($row, 'schedule');
                if (PbsJobKind::Prune === $kind && null === $schedule) {
                    throw new InvalidArgumentException('A PBS prune schedule is required.');
                }
                $disabled = $this->optionalBool($row, 'disable') ?? false;
                $syncDirection = null;
                $remote = null;
                $remoteStore = null;
                $remoteNamespace = null;
                if (PbsJobKind::Sync === $kind) {
                    $syncDirection = PbsSyncDirection::from($this->optionalString($row, 'sync-direction') ?? 'pull');
                    $remoteText = $this->optionalString($row, 'remote');
                    $remote = null === $remoteText ? null : new PbsJobId($remoteText);
                    $remoteStore = new PbsDatastoreId($this->requiredString($row, 'remote-store'));
                    $remoteNamespace = $this->namespace($row, 'remote-ns');
                }
                $lastRunText = $this->optionalString($row, 'last-run-upid');
                $lastStateText = $this->optionalString($row, 'last-run-state');
                $lastEnd = $this->optionalInt($row, 'last-run-endtime');
                if ((null === $lastRunText) !== (null === $lastStateText)) {
                    throw new InvalidArgumentException('The PBS last-run fields are inconsistent.');
                }
                $jobs[] = new PbsJobObservation(
                    $kind,
                    $id,
                    $store,
                    $namespace,
                    $schedule,
                    $disabled,
                    $syncDirection,
                    $remote,
                    $remoteStore,
                    $remoteNamespace,
                    null === $lastRunText ? null : new PbsUpid($lastRunText),
                    null === $lastStateText ? null : PbsTaskOutcome::fromRemoteStatus($lastStateText),
                    $lastEnd,
                    $this->optionalInt($row, 'next-run'),
                );
            } catch (InvalidArgumentException|ValueError) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
        }
        try {
            return new PbsJobListSnapshot($kind, $envelope->digest, $jobs);
        } catch (InvalidArgumentException) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
    }

    private function requiredString(\stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A required PBS job property is invalid.');
        }
        return $value;
    }

    private function optionalString(\stdClass $row, string $key): ?string
    {
        if (!ObjectPropertyInspector::exists($row, $key)) {
            return null;
        }
        $value = $row->{$key};
        if (!is_string($value)) {
            throw new InvalidArgumentException('An optional PBS job property is invalid.');
        }
        return $value;
    }

    private function optionalInt(\stdClass $row, string $key): ?int
    {
        if (!ObjectPropertyInspector::exists($row, $key)) {
            return null;
        }
        $value = $row->{$key};
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('An optional PBS job timestamp is invalid.');
        }
        return $value;
    }

    private function optionalBool(\stdClass $row, string $key): ?bool
    {
        if (!ObjectPropertyInspector::exists($row, $key)) {
            return null;
        }
        $value = $row->{$key};
        if (!is_bool($value)) {
            throw new InvalidArgumentException('An optional PBS job boolean is invalid.');
        }
        return $value;
    }

    private function namespace(\stdClass $row, string $key): ?PbsNamespace
    {
        $value = $this->optionalString($row, $key);
        return null === $value || '' === $value ? null : new PbsNamespace($value);
    }
}
