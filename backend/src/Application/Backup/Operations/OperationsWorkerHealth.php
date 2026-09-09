<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

final readonly class OperationsWorkerHealth
{
    public function __construct(
        public string $status,
        public string $heartbeatAt,
        public string $expiresAt,
        public bool $fresh,
        public ?string $nextActionAt,
        public string $buildVersion,
        public ?string $currentActivity,
    ) {
    }

    /** @return array{status:string,heartbeatAt:string,expiresAt:string,fresh:bool,nextActionAt:?string,buildVersion:string,currentActivity:?string} */
    public function toArray(): array
    {
        return [
            'status'=>$this->status, 'heartbeatAt'=>$this->heartbeatAt, 'expiresAt'=>$this->expiresAt,
            'fresh'=>$this->fresh, 'nextActionAt'=>$this->nextActionAt, 'buildVersion'=>$this->buildVersion,
            'currentActivity'=>$this->currentActivity,
        ];
    }
}
