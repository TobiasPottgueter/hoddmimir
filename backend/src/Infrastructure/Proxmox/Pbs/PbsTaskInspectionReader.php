<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsTaskInspection;
use App\Application\Proxmox\Pbs\PbsTaskPass;
use App\Application\Proxmox\Pbs\PbsUpid;
use App\Infrastructure\Logging\SensitiveDataRedactor;

final readonly class PbsTaskInspectionReader
{
    public function __construct(private SensitiveDataRedactor $redactor = new SensitiveDataRedactor()) {}

    public function inspect(PbsApiTransport $transport, PbsUpid $upid): PbsTaskInspection
    {
        $status = $exit = $end = $statusFailure = $logFailure = null;
        $lines = [];
        $truncated = false;
        try {
            [$status, $exit, $end] = $this->status($transport->get(PbsRequest::taskStatus($upid)), $upid);
        } catch (PbsReadFailure $failure) {
            $statusFailure = $failure->failureCode;
        }
        try {
            [$lines, $truncated] = $this->log($transport->get(PbsRequest::taskLog($upid)));
        } catch (PbsReadFailure $failure) {
            $logFailure = $failure->failureCode;
        }
        return new PbsTaskInspection($upid, $status, $exit, $end, $lines, $truncated, $statusFailure, $logFailure);
    }

    /** @return array{string, ?string, ?int} */
    private function status(PbsApiEnvelope $envelope, PbsUpid $upid): array
    {
        $row = $envelope->data;
        if (!$row instanceof \stdClass || ($row->upid ?? null) !== $upid->value
            || !\in_array($row->status ?? null, ['running', 'stopped'], true)
            || !is_string($row->user ?? null)
            || isset($row->tokenid) && !is_string($row->tokenid)
            || isset($row->exitstatus) && (!is_string($row->exitstatus) || strlen($row->exitstatus) > 8192)
            || 'running' === $row->status && (isset($row->exitstatus) || isset($row->endtime))
            || 'stopped' === $row->status && !isset($row->exitstatus)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        /** @var object{upid: string, status: string, user: string, tokenid?: string, exitstatus?: string, endtime?: int, type?: mixed, id?: mixed}&\stdClass $row Validated below and by the task-list reader. */
        // Reuse the strict UPID/identity and worker-id validation of the task-list adapter.
        $identity = clone $row;
        $identity->worker_type = $row->type ?? null;
        $identity->worker_id = $row->id ?? null;
        $identity->user = $row->user.(isset($row->tokenid) ? '!'.$row->tokenid : '');
        $identity->status = $row->exitstatus ?? null;
        (new PbsTaskPageReader())->read(new PbsApiEnvelope([$identity], null), PbsTaskPass::History);
        return [$row->status, isset($row->exitstatus) ? $this->redactor->redactMessage($row->exitstatus) : null, $row->endtime ?? null];
    }

    /** @return array{list<array{number: int, text: string}>, bool} */
    private function log(PbsApiEnvelope $envelope): array
    {
        if (!is_array($envelope->data) || !array_is_list($envelope->data) || count($envelope->data) > 501) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
        }
        $lines = [];
        foreach ($envelope->data as $index => $row) {
            if (!$row instanceof \stdClass || ($row->n ?? null) !== $index + 1
                || !is_string($row->t ?? null) || strlen($row->t) > 8192) {
                throw PbsReadFailure::for(PbsReadFailureCode::InvalidResponse);
            }
            if ($index < 500) {
                $lines[] = ['number' => $row->n, 'text' => $this->redactor->redactMessage($row->t)];
            }
        }
        return [$lines, count($envelope->data) > 500];
    }
}
