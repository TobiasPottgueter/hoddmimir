<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

final readonly class OperationsNotificationHealth
{
    /** @param array{pending:int,claimed:int,sent:int} $byState */
    public function __construct(
        public array $byState,
        public ?string $oldestUnsentAt,
        public ?string $lastErrorCode,
        public ?string $nextDeliveryAttemptAt,
    ) {
    }

    /** @return array{byState:array{pending:int,claimed:int,sent:int},oldestUnsentAt:?string,lastErrorCode:?string,nextDeliveryAttemptAt:?string} */
    public function toArray(): array
    {
        return [
            'byState'=>$this->byState, 'oldestUnsentAt'=>$this->oldestUnsentAt,
            'lastErrorCode'=>$this->lastErrorCode, 'nextDeliveryAttemptAt'=>$this->nextDeliveryAttemptAt,
        ];
    }
}
