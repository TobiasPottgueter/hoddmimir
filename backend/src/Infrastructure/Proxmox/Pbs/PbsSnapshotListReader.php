<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsBackupType;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsSnapshotObservation;
use App\Application\Proxmox\Pbs\PbsSnapshotVerification;
use App\Application\Proxmox\Pbs\PbsUpid;
use DateTimeImmutable;

final readonly class PbsSnapshotListReader
{
    /** @return list<PbsSnapshotObservation> */
    public function read(PbsApiEnvelope $envelope, PbsDatastoreId $datastore, PbsNamespace $namespace): array
    {
        if (!is_array($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        if (!array_is_list($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $rows = [];
        try {
            foreach ($envelope->data as $row) {
                if (!$row instanceof \stdClass) {
                    throw new \InvalidArgumentException();
                }
                $type = $this->requiredString($row, 'backup-type');
                $time = $row->{'backup-time'} ?? null;
                $files = $row->files ?? null;
                $protected = $row->protected ?? null;
                if (!is_int($time)) {
                    throw new \InvalidArgumentException();
                }
                if ($time < 1) {
                    throw new \InvalidArgumentException();
                }
                if (!is_array($files)) {
                    throw new \InvalidArgumentException();
                }
                if (!array_is_list($files)) {
                    throw new \InvalidArgumentException();
                }
                if (!is_bool($protected)) {
                    throw new \InvalidArgumentException();
                }
                foreach ($files as $file) {
                    if (!is_string($file)) {
                        throw new \InvalidArgumentException();
                    }
                }
                $verification = null;
                $verificationValue = $row->verification ?? null;
                if (null !== $verificationValue) {
                    if (!$verificationValue instanceof \stdClass) {
                        throw new \InvalidArgumentException();
                    }
                    $verification = new PbsSnapshotVerification(
                        $this->requiredString($verificationValue, 'state'),
                        new PbsUpid($this->requiredString($verificationValue, 'upid')),
                    );
                }
                $snapshot = new PbsSnapshotObservation(
                    $datastore,
                    $namespace,
                    PbsBackupType::from($type),
                    $this->requiredString($row, 'backup-id'),
                    new DateTimeImmutable('@'.$time),
                    $files,
                    $protected,
                    $this->optionalString($row, 'comment'),
                    $this->optionalString($row, 'fingerprint'),
                    $this->optionalString($row, 'owner'),
                    $this->optionalInteger($row, 'size'),
                    $verification,
                );
                if (isset($rows[$snapshot->key()])) {
                    throw new \InvalidArgumentException();
                }
                $rows[$snapshot->key()] = $snapshot;
            }
        } catch (\InvalidArgumentException|\ValueError) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        ksort($rows, SORT_STRING);
        return array_values($rows);
    }

    private function requiredString(\stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        if (!is_string($value)) {
            throw new \InvalidArgumentException();
        }
        return $value;
    }

    private function optionalString(\stdClass $row, string $key): ?string
    {
        $value = $row->{$key} ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new \InvalidArgumentException();
        }
        return $value;
    }

    private function optionalInteger(\stdClass $row, string $key): ?int
    {
        $value = $row->{$key} ?? null;
        if (null === $value) {
            return null;
        }
        if (!is_int($value) || $value < 0) {
            throw new \InvalidArgumentException();
        }
        return $value;
    }
}
