<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsTaskFilterFamily;
use App\Application\Proxmox\Pbs\PbsTaskObservation;
use App\Application\Proxmox\Pbs\PbsTaskOutcome;
use App\Application\Proxmox\Pbs\PbsTaskPage;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsUpid;
use App\Infrastructure\Validation\AsciiPatternValidator;
use App\Infrastructure\Validation\ObjectPropertyInspector;
use InvalidArgumentException;

final readonly class PbsTaskPageReader
{
    public function read(PbsApiEnvelope $envelope, PbsTaskPass $pass): PbsTaskPage
    {
        if (!is_array($envelope->data)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $tasks = [];
        $rawUpids = [];
        foreach ($envelope->data as $row) {
            if (!$row instanceof \stdClass) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            try {
                $upidText = $this->requiredString($row, 'upid');
                $upid = new PbsUpid($upidText);
                $rawUpids[] = $upid->value;
                $node = $this->requiredString($row, 'node');
                $workerId = $this->optionalString($row, 'worker_id');
                if ($this->requiredInt($row, 'pid') !== $upid->pid
                    || $this->requiredInt($row, 'pstart') !== $upid->processStart
                    || $this->requiredInt($row, 'starttime') !== $upid->startTime
                    || $this->requiredString($row, 'worker_type') !== $upid->workerType
                    || !$this->workerIdMatches($upid, $workerId)
                    || $this->requiredString($row, 'user') !== $upid->authId) {
                    throw new InvalidArgumentException('The PBS task row contradicts its UPID.');
                }
                $status = $this->optionalString($row, 'status');
                $endTime = $this->optionalInt($row, 'endtime');
                if (null === $status && null !== $endTime) {
                    throw new InvalidArgumentException('The PBS task state fields are inconsistent.');
                }
                if (!PbsTaskFilterFamily::allowsAny($upid->workerType)) {
                    continue;
                }
                $tasks[] = new PbsTaskObservation(
                    $upid,
                    $node,
                    PbsTaskPass::Running === $pass,
                    PbsTaskPass::History === $pass,
                    null === $status ? null : PbsTaskOutcome::fromRemoteStatus($status),
                    $endTime,
                );
            } catch (InvalidArgumentException) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
        }
        try {
            $fingerprintInput = count($envelope->data)."\0";
            $separator = '';
            foreach ($rawUpids as $rawUpid) {
                $fingerprintInput .= $separator.$rawUpid;
                $separator = "\0";
            }
            return new PbsTaskPage(
                $tasks,
                $envelope->total,
                count($envelope->data),
                hash('sha256', $fingerprintInput),
            );
        } catch (InvalidArgumentException) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
    }

    private function workerIdMatches(PbsUpid $upid, ?string $reportedWorkerId): bool
    {
        if (null === $reportedWorkerId || null === $upid->workerId) {
            return $reportedWorkerId === $upid->workerId;
        }

        $canonicalWorkerId = $this->canonicalWorkerId($reportedWorkerId);

        return null !== $canonicalWorkerId && hash_equals($upid->workerId, $canonicalWorkerId);
    }

    private function canonicalWorkerId(string $workerId): ?string
    {
        $length = strlen($workerId);
        if ($length < 1 || $length > 1024) {
            return null;
        }

        $canonical = '';
        for ($index = 0; $index < $length; ++$index) {
            $character = $workerId[$index];
            if ('/' === $character) {
                $canonical .= '-';
            } elseif ((0 !== $index || '.' !== $character)
                && AsciiPatternValidator::matches('/\A[_.0-9A-Za-z]\z/D', $character)) {
                $canonical .= $character;
            } else {
                $canonical .= sprintf('\\x%02x', ord($character));
            }

            if (strlen($canonical) > 1024) {
                return null;
            }
        }

        return $canonical;
    }

    private function requiredString(\stdClass $row, string $key): string
    {
        $value = $row->{$key} ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('A required PBS task property is invalid.');
        }
        return $value;
    }

    private function requiredInt(\stdClass $row, string $key): int
    {
        $value = $row->{$key} ?? null;
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('A required PBS task integer is invalid.');
        }
        return $value;
    }

    private function optionalString(\stdClass $row, string $key): ?string
    {
        if (!ObjectPropertyInspector::exists($row, $key)) {
            return null;
        }
        $value = $row->{$key};
        if (null === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('An optional PBS task property is invalid.');
        }
        return '' === $value ? null : $value;
    }

    private function optionalInt(\stdClass $row, string $key): ?int
    {
        if (!ObjectPropertyInspector::exists($row, $key)) {
            return null;
        }
        $value = $row->{$key};
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException('An optional PBS task integer is invalid.');
        }
        return $value;
    }
}
