<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsAclEvidence
{
    /** @var list<PbsAclIssueCode> */ public array $issues;
    private bool $taskReadEvidence;
    private bool $datastoreReadEvidence;
    private bool $remoteReadEvidence;

    public function __construct(
        PbsEffectivePermission $tasks,
        PbsEffectivePermission $datastores,
        PbsEffectivePermission $remotes,
    ) {
        $issues = [];
        $this->taskReadEvidence = '/system/tasks' === $tasks->path && $tasks->grants('Sys.Audit');
        $this->datastoreReadEvidence = '/datastore' === $datastores->path
            && $datastores->propagates('Datastore.Audit');
        $this->remoteReadEvidence = '/remote' === $remotes->path && $remotes->propagates('Remote.Audit');
        if (!$this->taskReadEvidence) {
            $issues[] = PbsAclIssueCode::MissingSystemTaskAudit;
        }
        if (!$this->datastoreReadEvidence) {
            $issues[] = PbsAclIssueCode::MissingDatastoreAuditPropagation;
        }
        if (!$this->remoteReadEvidence) {
            $issues[] = PbsAclIssueCode::MissingRemoteAuditPropagation;
        }
        $this->issues = $issues;
    }

    public function hasBroadReadEvidence(): bool
    {
        return [] === $this->issues;
    }

    public function hasTaskReadEvidence(): bool
    {
        return $this->taskReadEvidence;
    }

    public function hasDatastoreReadEvidence(): bool
    {
        return $this->datastoreReadEvidence;
    }

    public function hasRemoteReadEvidence(): bool
    {
        return $this->remoteReadEvidence;
    }

    public function permitsAbsenceDecisions(): bool
    {
        return false;
    }
}
