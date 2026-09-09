<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Application\Proxmox\Pbs\PbsAclEvidence;
use App\Application\Proxmox\Pbs\PbsJobKind;
use App\Application\Proxmox\Pbs\PbsJobListSnapshot;
use App\Application\Proxmox\Pbs\PbsTaskScanSnapshot;
use InvalidArgumentException;

final readonly class PbsExternalMonitoringSnapshot
{
    /** @var array<string, string> */ public array $errors;

    /**
     * @param array<string, string> $errors Keys: acl, prune, sync, verify, tasks.
     * @param list<\App\Application\Proxmox\Pbs\PbsTaskInspection> $inspections
     */
    public function __construct(
        public ?PbsAclEvidence $acl,
        public ?PbsJobListSnapshot $pruneJobs,
        public ?PbsJobListSnapshot $syncJobs,
        public ?PbsJobListSnapshot $verifyJobs,
        public ?PbsTaskScanSnapshot $tasks,
        array $errors,
        public array $inspections = [],
    ) {
        foreach ($errors as $key => $code) {
            if (!isset(['acl' => true, 'prune' => true, 'sync' => true, 'verify' => true, 'tasks' => true][$key])
                || '' === $code || strlen($code) > 64
                || strlen($code) !== strspn($code, 'abcdefghijklmnopqrstuvwxyz0123456789_')) {
                throw new InvalidArgumentException('The PBS monitoring error map is invalid.');
            }
        }
        if (null !== $pruneJobs && PbsJobKind::Prune !== $pruneJobs->kind
            || null !== $syncJobs && PbsJobKind::Sync !== $syncJobs->kind
            || null !== $verifyJobs && PbsJobKind::Verify !== $verifyJobs->kind
            || (null === $acl) !== isset($errors['acl'])
            || (null === $pruneJobs) !== isset($errors['prune'])
            || (null === $syncJobs) !== isset($errors['sync'])
            || (null === $verifyJobs) !== isset($errors['verify'])
            || (null === $tasks) !== isset($errors['tasks'])) {
            throw new InvalidArgumentException('The PBS monitoring snapshot is inconsistent.');
        }
        ksort($errors, SORT_STRING);
        $this->errors = $errors;
    }
}
