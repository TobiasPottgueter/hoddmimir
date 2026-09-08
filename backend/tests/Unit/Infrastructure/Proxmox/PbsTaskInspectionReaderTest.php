<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsUpid;
use App\Infrastructure\Proxmox\Pbs\PbsApiEnvelope;
use App\Infrastructure\Proxmox\Pbs\PbsApiTransport;
use App\Infrastructure\Proxmox\Pbs\PbsRequest;
use App\Infrastructure\Proxmox\Pbs\PbsTaskInspectionReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PbsTaskInspectionReaderTest extends TestCase
{
    public function testVersionStatusLogRedactionAndLimits(): void
    {
        foreach ([3, 4] as $version) {
            $row = self::statusRow();
            if (4 === $version) $row->endtime = self::upid()->startTime + 10;
            $row->exitstatus = 'Error: password=not-a-real-secret';
            $transport = new InspectionTransport($row, [(object) ['n' => 1, 't' => 'Authorization: PBSAPIToken=synthetic']]);
            $result = (new PbsTaskInspectionReader())->inspect($transport, self::upid());
            self::assertSame('stopped', $result->status);
            self::assertSame('Error: password=[REDACTED]', $result->exitStatus);
            self::assertSame('Authorization: [REDACTED]', $result->lines[0]['text']);
            self::assertNull($result->statusFailure);
            self::assertNull($result->logFailure);
            self::assertFalse($result->truncated);
            self::assertSame(['start' => 0, 'limit' => 501], $transport->requests[1]->query);
            self::assertSame(['nodes', 'localhost', 'tasks', self::upid()->value, 'status'], $transport->requests[0]->pathSegments);
        }
        $running = self::statusRow();
        $running->status = 'running';
        unset($running->exitstatus, $running->tokenid);
        $running->user = self::upid()->authId;
        $result = (new PbsTaskInspectionReader())->inspect(new InspectionTransport($running, []), self::upid());
        self::assertSame('running', $result->status);
        self::assertNull($result->exitStatus);
        self::assertNull($result->statusFailure);
    }

    public function testExactBoundaryAndLookAhead(): void
    {
        foreach ([500, 501] as $count) {
            $lines = array_map(static fn (int $n): object => (object) ['n' => $n, 't' => 'safe'], range(1, $count));
            $result = (new PbsTaskInspectionReader())->inspect(new InspectionTransport(self::statusRow(), $lines), self::upid());
            self::assertCount(500, $result->lines);
            self::assertSame(501 === $count, $result->truncated);
        }
    }

    #[DataProvider('invalidStatus')]
    public function testMalformedStatusDoesNotLoseUsableLog(mixed $row): void
    {
        $result = (new PbsTaskInspectionReader())->inspect(new InspectionTransport($row, []), self::upid());
        self::assertSame(PbsReadFailureCode::InvalidResponse, $result->statusFailure);
        self::assertNull($result->logFailure);
        self::assertNull($result->status);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidStatus(): iterable
    {
        yield 'not object' => [[]];
        foreach (['upid' => 'wrong', 'status' => 'unknown', 'user' => 1, 'tokenid' => 1, 'exitstatus' => 1, 'pid' => 0, 'endtime' => 'bad'] as $key => $value) {
            $row = self::statusRow(); $row->{$key} = $value; yield $key => [$row];
        }
        $row = self::statusRow(); $row->exitstatus = str_repeat('x', 8193); yield 'long exit' => [$row];
        $row = self::statusRow(); $row->status = 'running'; yield 'running exit' => [$row];
        $row = self::statusRow(); $row->status = 'running'; unset($row->exitstatus); $row->endtime = 1; yield 'running end' => [$row];
        $row = self::statusRow(); unset($row->exitstatus); yield 'stopped no exit' => [$row];
        $row = self::statusRow(); unset($row->type); yield 'missing type' => [$row];
    }

    #[DataProvider('invalidLogs')]
    public function testMalformedLogsDoNotLoseStatus(mixed $lines): void
    {
        $result = (new PbsTaskInspectionReader())->inspect(new InspectionTransport(self::statusRow(), $lines), self::upid());
        self::assertSame(PbsReadFailureCode::InvalidResponse, $result->logFailure);
        self::assertNull($result->statusFailure);
        self::assertSame([], $result->lines);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidLogs(): iterable
    {
        yield 'not array' => [null];
        yield 'not list' => [['bad' => 1]];
        yield 'too many' => [array_fill(0, 502, null)];
        yield 'not object' => [[null]];
        yield 'wrong index' => [[(object) ['n' => 2, 't' => 'safe']]];
        yield 'not text' => [[(object) ['n' => 1, 't' => 1]]];
        yield 'long text' => [[(object) ['n' => 1, 't' => str_repeat('x', 8193)]]];
    }

    public function testPermissionFailuresAreExplicitAndIndependent(): void
    {
        $transport = new InspectionTransport(null, null, true);
        $result = (new PbsTaskInspectionReader())->inspect($transport, self::upid());
        self::assertSame(PbsReadFailureCode::PermissionDenied, $result->statusFailure);
        self::assertSame(PbsReadFailureCode::PermissionDenied, $result->logFailure);
        self::assertCount(2, $transport->requests);
    }

    private static function upid(): PbsUpid { return new PbsUpid('UPID:pbs:00000001:00000002:00000003:65000000:verify:store:collector@pbs!audit:'); }
    private static function statusRow(): \stdClass
    {
        $upid = self::upid();
        return (object) ['upid' => $upid->value, 'node' => 'localhost', 'pid' => $upid->pid, 'pstart' => $upid->processStart,
            'starttime' => $upid->startTime, 'type' => 'verify', 'id' => 'store', 'user' => 'collector@pbs', 'tokenid' => 'audit',
            'status' => 'stopped', 'exitstatus' => 'OK'];
    }
}

final class InspectionTransport implements PbsApiTransport
{
    /** @var list<PbsRequest> */ public array $requests = [];
    public function __construct(private mixed $status, private mixed $lines, private bool $denied = false) {}
    public function get(PbsRequest $request): PbsApiEnvelope
    {
        $this->requests[] = $request;
        if ($this->denied) throw PbsReadFailure::for(PbsReadFailureCode::PermissionDenied);
        return new PbsApiEnvelope('status' === $request->pathSegments[4] ? $this->status : $this->lines, null);
    }
}
