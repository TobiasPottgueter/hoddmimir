<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveExecutorAclClient;
use App\Domain\Shared\Clock;

final readonly class ProbeExecutorPermissions
{
    public function __construct(
        private PveExecutorAclClient $client,
        private ExecutorPermissionEvidenceStore $store,
        private Clock $clock,
    ) {
    }

    public function execute(ExecutorAclProbeCommand $command): ExecutorPermissionEvidence
    {
        $guestPath = '/vms/'.$command->submission->vmid;
        $storagePath = '/storage/'.$command->submission->storage;
        $guest = $this->client->permission($guestPath);
        $storage = $this->client->permission($storagePath);
        $missing = [];
        if (!$guest->grants('VM.Backup')) {
            $missing[] = 'VM.Backup';
        }
        if (!$storage->grants('Datastore.AllocateSpace')) {
            $missing[] = 'Datastore.AllocateSpace';
        }
        $vmBackupAuthorized = $guest->grants('VM.Backup');
        $datastoreAllocateAuthorized = $storage->grants('Datastore.AllocateSpace');
        $evidence = new ExecutorPermissionEvidence(
            $command->connectionId,
            $command->clusterId,
            $command->guestId,
            $command->targetId,
            $command->nodeId,
            $command->storageId,
            $vmBackupAuthorized,
            $datastoreAllocateAuthorized,
            $vmBackupAuthorized && $datastoreAllocateAuthorized,
            $this->clock->now(),
            $guestPath,
            $storagePath,
            $missing,
        );
        $this->store->persist($evidence);

        return $evidence;
    }
}
