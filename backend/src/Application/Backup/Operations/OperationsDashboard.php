<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

final readonly class OperationsDashboard
{
    /**
     * @param array{collector:?OperationsWorkerHealth,backup:?OperationsWorkerHealth} $workers
     * @param array{systems:int,nodes:int,guests:int,targets:int,policies:int} $resources
     * @param array<string,int> $requestsByState
     * @param array{manual:int,never_backed_up:int,max_age:int,bytes_written:int} $requestsByReason
     * @param array<string,int> $runsByState
     * @param list<array{id:string,occurredAt:string,eventType:string,outcome:string,subjectType:?string,reasonCode:?string}> $recentAuditEvents
     */
    public function __construct(
        public array $workers,
        public ?OperationsCollectorSchedule $collectorSchedule,
        public array $resources,
        public array $requestsByState,
        public array $requestsByReason,
        public array $runsByState,
        public ?string $oldestPendingAt,
        public ?OperationsLastSuccessfulRun $lastSuccessfulRun,
        public int $staleEvidence,
        public int $shadowBlockers,
        public int $openProblems,
        public OperationsNotificationHealth $notifications,
        public array $recentAuditEvents,
        public bool $auditVisible,
    ) {
    }

    /** @return array{
     * workers:array{collector:?array<string,mixed>,backup:?array<string,mixed>},
     * collectorSchedule:?array<string,mixed>,resources:array{systems:int,nodes:int,guests:int,targets:int,policies:int},
     * requestsByState:array<string,int>,requestsByReason:array{manual:int,never_backed_up:int,max_age:int,bytes_written:int},runsByState:array<string,int>,oldestPendingAt:?string,lastSuccessfulRun:?array<string,mixed>,
     * staleEvidence:int,shadowBlockers:int,openProblems:int,notifications:array<string,mixed>,
     * recentAuditEvents:list<array{id:string,occurredAt:string,eventType:string,outcome:string,subjectType:?string,reasonCode:?string}>,auditVisible:bool
     * } */
    public function toArray(): array
    {
        return [
            'workers' => array_map(static fn (?OperationsWorkerHealth $worker): ?array => $worker?->toArray(), $this->workers),
            'collectorSchedule' => $this->collectorSchedule?->toArray(),
            'resources' => $this->resources,
            'requestsByState' => $this->requestsByState,
            'requestsByReason' => $this->requestsByReason,
            'runsByState' => $this->runsByState,
            'oldestPendingAt' => $this->oldestPendingAt,
            'lastSuccessfulRun' => $this->lastSuccessfulRun?->toArray(),
            'staleEvidence' => $this->staleEvidence,
            'shadowBlockers' => $this->shadowBlockers,
            'openProblems' => $this->openProblems,
            'notifications' => $this->notifications->toArray(),
            'recentAuditEvents' => $this->recentAuditEvents,
            'auditVisible' => $this->auditVisible,
        ];
    }
}
