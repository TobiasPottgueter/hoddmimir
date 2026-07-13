<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Operations;

use App\Application\Backup\Operations\OperationsCollectorSchedule;
use App\Application\Backup\Operations\OperationsDashboard;
use App\Application\Backup\Operations\OperationsNotificationHealth;
use App\Application\Backup\Operations\OperationsLastSuccessfulRun;
use App\Application\Backup\Operations\OperationsWorkerHealth;
use PHPUnit\Framework\TestCase;

final class OperationsProjectionTest extends TestCase
{
    public function testClosedDashboardProjectionSerializesNestedEvidence(): void
    {
        $worker = new OperationsWorkerHealth(
            'idle', '2026-07-13T00:00:00.000000Z', '2026-07-13T00:01:00.000000Z', true,
            '2026-07-13T00:02:00.000000Z', '2.0.0', 'inventory cycle',
        );
        $notifications = new OperationsNotificationHealth(
            ['pending'=>1, 'claimed'=>0, 'sent'=>2], '2026-07-13T00:00:00.000000Z', 'delivery_failed', '2026-07-13T00:03:00.000000Z',
        );
        $schedule = new OperationsCollectorSchedule(
            '2026-07-13T00:02:00.000000Z',
            '2026-07-13T00:00:00.000000Z',
            '2026-07-13T00:00:30.000000Z',
            '2026-07-13T00:00:29.000000Z',
        );
        $lastSuccessfulRun = new OperationsLastSuccessfulRun(
            '00112233-4455-6677-8899-aabbccddeeff',
            'guest',
            101,
            'node',
            'target',
            '2026-07-13T00:00:00.000000Z',
        );
        $recentAuditEvents = [[
            'id' => 'event-id',
            'occurredAt' => '2026-07-13T00:00:00.000000Z',
            'eventType' => 'backup_requested',
            'outcome' => 'succeeded',
            'subjectType' => 'guest',
            'reasonCode' => null,
        ]];
        $dashboard = new OperationsDashboard(
            ['collector'=>$worker, 'backup'=>null],
            $schedule,
            ['systems'=>1, 'nodes'=>2, 'guests'=>3, 'targets'=>4, 'policies'=>5],
            ['pending'=>1], ['manual'=>1,'never_backed_up'=>0,'max_age'=>0,'bytes_written'=>0], ['running'=>1], '2026-07-13T00:00:00.000000Z',
            $lastSuccessfulRun,
            1, 2, 3,
            $notifications, $recentAuditEvents, true,
        );

        $serialized = $dashboard->toArray();
        self::assertSame([
            'workers' => [
                'collector' => [
                    'status' => 'idle',
                    'heartbeatAt' => '2026-07-13T00:00:00.000000Z',
                    'expiresAt' => '2026-07-13T00:01:00.000000Z',
                    'fresh' => true,
                    'nextActionAt' => '2026-07-13T00:02:00.000000Z',
                    'buildVersion' => '2.0.0',
                    'currentActivity' => 'inventory cycle',
                ],
                'backup' => null,
            ],
            'collectorSchedule' => [
                'nextScanAt' => '2026-07-13T00:02:00.000000Z',
                'lastAttemptStartedAt' => '2026-07-13T00:00:00.000000Z',
                'lastAttemptFinishedAt' => '2026-07-13T00:00:30.000000Z',
                'lastSuccessfulAppliedAt' => '2026-07-13T00:00:29.000000Z',
            ],
            'resources' => ['systems'=>1, 'nodes'=>2, 'guests'=>3, 'targets'=>4, 'policies'=>5],
            'requestsByState' => ['pending'=>1],
            'requestsByReason' => ['manual'=>1,'never_backed_up'=>0,'max_age'=>0,'bytes_written'=>0],
            'runsByState' => ['running'=>1],
            'oldestPendingAt' => '2026-07-13T00:00:00.000000Z',
            'lastSuccessfulRun' => [
                'runId' => '00112233-4455-6677-8899-aabbccddeeff',
                'guestName' => 'guest',
                'vmid' => 101,
                'nodeName' => 'node',
                'targetName' => 'target',
                'finishedAt' => '2026-07-13T00:00:00.000000Z',
            ],
            'staleEvidence' => 1,
            'shadowBlockers' => 2,
            'openProblems' => 3,
            'notifications' => [
                'byState' => ['pending'=>1, 'claimed'=>0, 'sent'=>2],
                'oldestUnsentAt' => '2026-07-13T00:00:00.000000Z',
                'lastErrorCode' => 'delivery_failed',
                'nextDeliveryAttemptAt' => '2026-07-13T00:03:00.000000Z',
            ],
            'recentAuditEvents' => $recentAuditEvents,
            'auditVisible' => true,
        ], $serialized);
    }

    public function testMissingCollectorScheduleRemainsExplicitlyNull(): void
    {
        $dashboard = new OperationsDashboard(
            ['collector'=>null, 'backup'=>null], null,
            ['systems'=>0, 'nodes'=>0, 'guests'=>0, 'targets'=>0, 'policies'=>0],
            [], ['manual'=>0,'never_backed_up'=>0,'max_age'=>0,'bytes_written'=>0], [], null, null, 0, 0, 0,
            new OperationsNotificationHealth(['pending'=>0, 'claimed'=>0, 'sent'=>0], null, null, null),
            [], false,
        );
        self::assertNull($dashboard->toArray()['collectorSchedule']);
    }
}
