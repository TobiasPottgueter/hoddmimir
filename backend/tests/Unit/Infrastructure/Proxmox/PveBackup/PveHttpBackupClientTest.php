<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\BuildPveVzdumpPayload;
use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupCompression;
use App\Application\Proxmox\Pve\PveBackupFailureRecipients;
use App\Application\Proxmox\Pve\PveBackupMode;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionStatus;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStopStatus;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Proxmox\PveBackup\PveBackupApiTransport;
use App\Infrastructure\Proxmox\PveBackup\PveBackupSubmissionReader;
use App\Infrastructure\Proxmox\PveBackup\PveBackupTaskLogReader;
use App\Infrastructure\Proxmox\PveBackup\PveBackupWriteTransportResult;
use App\Infrastructure\Proxmox\PveBackup\PveBackupWriteTransportStatus;
use App\Infrastructure\Proxmox\PveBackup\PveHttpBackupClient;
use App\Infrastructure\Proxmox\PveTaskPageReader;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PveHttpBackupClientTest extends TestCase
{
    public function testAcceptedSubmissionUsesOneSafeVzdumpRequestAndReturnsTheReportedUpid(): void
    {
        $transport = new RecordingBackupTransport(
            writes: [PveBackupWriteTransportResult::responded($this->upid()->raw)],
        );
        $result = $this->client($transport)->submit($this->submission());

        self::assertSame(PveBackupSubmissionStatus::Accepted, $result->status);
        self::assertSame($this->upid()->raw, $result->upid?->raw);
        self::assertSame([['nodes', 'pve-a', 'vzdump']], $transport->postPaths);
        self::assertSame(101, $transport->postForms[0]['vmid']);
        self::assertCount(1, $transport->postPaths);
    }

    public function testAmbiguousSubmissionNeverInventsAnUpidOrRetries(): void
    {
        $transport = new RecordingBackupTransport(writes: [PveBackupWriteTransportResult::ambiguous()]);
        $result = $this->client($transport)->submit($this->submission());

        self::assertSame(PveBackupSubmissionStatus::Ambiguous, $result->status);
        self::assertNull($result->upid);
        self::assertCount(1, $transport->postPaths);
    }

    public function testEveryUnusableSuccessfulSubmissionResponseIsAmbiguous(): void
    {
        foreach ([
            PveBackupWriteTransportResult::respondedWithoutData(),
            PveBackupWriteTransportResult::responded(true),
            PveBackupWriteTransportResult::responded('not-an-upid'),
            PveBackupWriteTransportResult::responded(str_replace('pve-a', 'pve-b', $this->upid()->raw)),
            PveBackupWriteTransportResult::responded(str_replace(':101:', ':102:', $this->upid()->raw)),
        ] as $response) {
            $transport = new RecordingBackupTransport(writes: [$response]);

            $result = $this->client($transport)->submit($this->submission());

            self::assertSame(PveBackupSubmissionStatus::Ambiguous, $result->status);
            self::assertNull($result->upid);
            self::assertCount(1, $transport->postPaths);
        }
    }

    public function testSubmissionReaderRejectsWrongTypesMalformedUpidsAndIdentityMismatches(): void
    {
        $reader = new PveBackupSubmissionReader();
        foreach ([
            42,
            'not-an-upid',
            str_replace('pve-a', 'pve-b', $this->upid()->raw),
            str_replace(':101:', ':102:', $this->upid()->raw),
        ] as $data) {
            $this->assertFailure(
                PveBackupApiFailureCode::InvalidResponse,
                fn () => $reader->read($this->submission(), $data),
            );
        }
    }

    public function testStatusLogAndStopAreTypedAndUseTheUpidRoute(): void
    {
        $logObject = new \stdClass();
        $logObject->n = 2;
        $logObject->t = 'second';
        $transport = new RecordingBackupTransport(
            reads: [
                (object) [
                    'upid' => $this->upid()->raw,
                    'node' => 'pve-a',
                    'pid' => 42,
                    'pstart' => 1_000_000,
                    'starttime' => 1_728_051_200,
                    'type' => 'vzdump',
                    'id' => '101',
                    'user' => 'backup@pve',
                    'status' => 'running',
                ],
                [['n' => 1, 't' => 'first'], $logObject],
            ],
            writes: [PveBackupWriteTransportResult::respondedWithoutData()],
        );
        $client = $this->client($transport);

        self::assertSame(PveTaskLifecycle::Running, $client->taskStatus($this->upid())->lifecycle);
        $log = $client->taskLog($this->upid(), new PveTaskLogQuery(0, 2));
        self::assertSame(['first', 'second'], array_map(static fn ($entry): string => $entry->text, $log->entries));
        self::assertSame(PveTaskStopStatus::Requested, $client->stopTask($this->upid())->status);
        self::assertStringEndsWith('/status', implode('/', $transport->getPaths[0]));
        self::assertStringEndsWith('/log', implode('/', $transport->getPaths[1]));
        self::assertSame(['start' => 0, 'limit' => 2], $transport->getQueries[1]);
        self::assertSame(['nodes', 'pve-a', 'tasks', $this->upid()->raw], $transport->deletePaths[0]);
    }

    public function testInvalidTaskStatusIsMappedToTheBackupFailureContract(): void
    {
        $transport = new RecordingBackupTransport(reads: [[]]);
        $this->assertFailure(
            PveBackupApiFailureCode::InvalidResponse,
            fn () => $this->client($transport)->taskStatus($this->upid()),
        );
    }

    public function testTaskListRequiresNodeAuditAndAcceptsNonPropagatingGrant(): void
    {
        $transport = new RecordingBackupTransport(reads: [[]]);
        foreach ([null, false, '1', 2] as $permission) {
            $transport->taskPermission = $permission;
            $this->assertFailure(PveBackupApiFailureCode::PermissionDenied,
                fn () => $this->client($transport)->taskPage('pve-a', PveTaskQuery::active()));
        }
        self::assertSame([], $transport->getPaths);
        $transport->taskPermission = 0;
        self::assertTrue($this->client($transport)->taskPage('pve-a', PveTaskQuery::active())->isComplete());
        self::assertSame(5, $transport->permissionReads);
    }

    public function testTaskPageUsesTypedQueryAndMapsReaderFailure(): void
    {
        $upid = $this->upid();
        $row = [
            'upid' => $upid->raw, 'node' => $upid->node, 'pid' => $upid->pid,
            'pstart' => $upid->processStart, 'starttime' => $upid->startTime,
            'type' => $upid->type, 'id' => $upid->id, 'user' => $upid->user, 'status' => 'RUNNING',
        ];
        $query = PveTaskQuery::active(limit: 10);
        $transport = new RecordingBackupTransport(reads: [[$row]]);
        $page = $this->client($transport)->taskPage('pve-a', $query);
        self::assertCount(1, $page->tasks);
        self::assertSame(['nodes', 'pve-a', 'tasks'], $transport->getPaths[0]);
        self::assertSame($query->parameters(), $transport->getQueries[0]);

        $this->assertFailure(
            PveBackupApiFailureCode::InvalidResponse,
            fn () => $this->client(new RecordingBackupTransport(reads: ['bad']))->taskPage('pve-a', $query),
        );
    }

    public function testTaskPermissionsAcceptJsonObjectsAndRejectMalformedMatrices(): void
    {
        $transport = new RecordingBackupTransport(reads: [[], []]);
        foreach ([(object) ['/nodes/pve-a' => (object) ['Sys.Audit' => 1]],
            ['/nodes/pve-a' => (object) ['Sys.Audit' => 0]]] as $matrix) {
            $transport->permissionMatrix = $matrix;
            self::assertTrue($this->client($transport)->taskPage('pve-a', PveTaskQuery::active())->isComplete());
        }
        foreach ([false, [], ['/nodes/pve-a' => false], (object) []] as $matrix) {
            $transport->permissionMatrix = $matrix;
            $this->assertFailure(PveBackupApiFailureCode::PermissionDenied,
                fn () => $this->client($transport)->taskPage('pve-a', PveTaskQuery::active()));
        }
        self::assertCount(2, $transport->getPaths);
    }

    public function testLogReaderRejectsNonListsOversizeRowsMalformedRowsEntriesAndOrdering(): void
    {
        $reader = new PveBackupTaskLogReader();
        $query = new PveTaskLogQuery(0, 1);
        foreach ([
            new \stdClass(),
            [['n' => 1, 't' => 'a'], ['n' => 2, 't' => 'b']],
            [['n' => '1', 't' => 'a']],
            [['n' => 1, 't' => 2]],
            [['n' => -1, 't' => 'a']],
        ] as $data) {
            $this->assertFailure(PveBackupApiFailureCode::InvalidResponse, fn () => $reader->read($query, $data));
        }
        $this->assertFailure(
            PveBackupApiFailureCode::InvalidResponse,
            fn () => $reader->read(new PveTaskLogQuery(0, 2), [['n' => 2, 't' => 'a'], ['n' => 2, 't' => 'b']]),
        );
    }

    public function testStopIsAmbiguousForTransportFailureOrUnexpectedResponseData(): void
    {
        $ambiguous = new RecordingBackupTransport(writes: [PveBackupWriteTransportResult::ambiguous()]);
        self::assertSame(PveTaskStopStatus::Ambiguous, $this->client($ambiguous)->stopTask($this->upid())->status);

        $unexpected = new RecordingBackupTransport(writes: [PveBackupWriteTransportResult::responded(true)]);
        self::assertSame(
            PveTaskStopStatus::Ambiguous,
            $this->client($unexpected)->stopTask($this->upid())->status,
        );
    }

    public function testWriteTransportResultEnforcesNullAndStatusInvariants(): void
    {
        self::assertNull(PveBackupWriteTransportResult::respondedWithoutData()->decodedData());
        self::assertSame('ok', PveBackupWriteTransportResult::responded('ok')->decodedData());
        self::assertNull(PveBackupWriteTransportResult::ambiguous()->decodedData());

        try {
            PveBackupWriteTransportResult::responded(null);
            self::fail('Null response used the non-null constructor.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        foreach ([
            [PveBackupWriteTransportStatus::Responded, null],
            [PveBackupWriteTransportStatus::Ambiguous, true],
        ] as $arguments) {
            try {
                new PveBackupWriteTransportResult(...$arguments);
                self::fail('Inconsistent transport result was constructed.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function client(RecordingBackupTransport $transport): PveHttpBackupClient
    {
        $version = new PveVersion(8, 4, 1, '1', '8.4.1', 'abcdef12');
        return new PveHttpBackupClient(
            $transport,
            $version,
            new BuildPveVzdumpPayload(),
            new PveBackupSubmissionReader(),
            new PveTaskStatusReader($version),
            new PveBackupTaskLogReader(),
            new PveTaskPageReader(),
        );
    }

    private function submission(): PveBackupSubmission
    {
        return new PveBackupSubmission(
            'pve-a',
            101,
            PveGuestType::Qemu,
            'backup-store',
            PveBackupMode::Snapshot,
            PveBackupCompression::Zstd,
            new PveBackupFailureRecipients(['ops@example.invalid']),
        );
    }

    private function upid(): PveUpid
    {
        return PveUpid::parse('UPID:pve-a:0000002A:000F4240:67000000:vzdump:101:backup@pve:');
    }

    private function assertFailure(PveBackupApiFailureCode $code, callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected typed backup API failure.');
        } catch (PveBackupApiFailure $failure) {
            self::assertSame($code, $failure->failureCode);
        }
    }
}

/** @internal */
final class RecordingBackupTransport implements PveBackupApiTransport
{
    /** @var list<mixed> */
    private array $reads;
    public mixed $taskPermission = 1;
    public mixed $permissionMatrix = null;
    public int $permissionReads = 0;

    /** @var list<PveBackupWriteTransportResult> */
    private array $writes;

    /** @var list<list<string>> */
    public array $getPaths = [];

    /** @var list<array<string, string|int|bool|null>> */
    public array $getQueries = [];

    /** @var list<list<string>> */
    public array $postPaths = [];

    /** @var list<array<string, string|int>> */
    public array $postForms = [];

    /** @var list<list<string>> */
    public array $deletePaths = [];

    /**
     * @param list<mixed>                         $reads
     * @param list<PveBackupWriteTransportResult> $writes
     */
    public function __construct(array $reads = [], array $writes = [])
    {
        $this->reads = $reads;
        $this->writes = $writes;
    }

    public function get(array $pathSegments, array $query = []): mixed
    {
        if (['access', 'permissions'] === $pathSegments) {
            ++$this->permissionReads;
            return $this->permissionMatrix ?? [(string) $query['path'] => ['Sys.Audit' => $this->taskPermission]];
        }
        $this->getPaths[] = $pathSegments;
        $this->getQueries[] = $query;
        return array_shift($this->reads);
    }

    public function post(array $pathSegments, array $form): PveBackupWriteTransportResult
    {
        $this->postPaths[] = $pathSegments;
        $this->postForms[] = $form;
        return array_shift($this->writes) ?? throw new \LogicException('missing write');
    }

    public function delete(array $pathSegments): PveBackupWriteTransportResult
    {
        $this->deletePaths[] = $pathSegments;
        return array_shift($this->writes) ?? throw new \LogicException('missing write');
    }
}
