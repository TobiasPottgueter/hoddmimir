<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

final readonly class OperationsLastSuccessfulRun
{
    public function __construct(
        public string $runId,
        public ?string $guestName,
        public int $vmid,
        public string $nodeName,
        public string $targetName,
        public string $finishedAt,
    ) {
    }

    /** @return array{runId:string,guestName:?string,vmid:int,nodeName:string,targetName:string,finishedAt:string} */
    public function toArray(): array
    {
        return [
            'runId'=>$this->runId, 'guestName'=>$this->guestName, 'vmid'=>$this->vmid,
            'nodeName'=>$this->nodeName, 'targetName'=>$this->targetName, 'finishedAt'=>$this->finishedAt,
        ];
    }
}
