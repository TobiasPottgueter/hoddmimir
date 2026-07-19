<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveBackupInventoryIssueCode;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveTaskLifecycle;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Proxmox\PveBackupJobReader;
use App\Infrastructure\Proxmox\PveTaskPageReader;
use App\Infrastructure\Proxmox\PveTaskStatusReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveBackupTaskReadersTest extends TestCase
{
    public function testBackupJobReaderRejectsNonListsAndKeepsValidRowsAlongsideIssues(): void
    {
        $reader = new PveBackupJobReader($this->version(7));
        $this->assertFailure(static fn () => $reader->read('bad'));
        $this->assertFailure(static fn () => $reader->read(['not' => 'a-list']));

        $inventory = $reader->read([
            'bad-row',
            [],
            ['id' => "bad id"],
            ['id' => ''],
            ['id' => str_repeat('a', 51)],
            ['id' => 'job', 'maxfiles' => 3, 'prune-backups' => 'keep-last=3,keep-all=0'],
            ['id' => 'job'],
            ['id' => 'bad-max', 'maxfiles' => -1],
            ['id' => 'bad-prune', 'prune-backups' => 'broken'],
            (object) ['id' => 'object', 'prune-backups' => (object) ['keep-weekly' => 4]],
        ]);

        self::assertCount(4, $inventory->jobs);
        self::assertFalse($inventory->isComplete());
        self::assertSame(3, $inventory->jobs[0]->legacyMaxFiles);
        self::assertSame(['keep-all' => false, 'keep-last' => 3], $inventory->jobs[0]->pruneBackups?->signature());
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $this->codes($inventory));
        self::assertContains(PveBackupInventoryIssueCode::MissingRequiredField, $this->codes($inventory));
        self::assertContains(PveBackupInventoryIssueCode::DuplicateJob, $this->codes($inventory));
    }

    #[DataProvider('unsafeJobIdProvider')]
    public function testBackupJobIdsMustBeNonEmptyVisibleAscii(string $id): void
    {
        $inventory = (new PveBackupJobReader($this->version(7)))->read([['id' => $id]]);

        self::assertSame([], $inventory->jobs);
        self::assertSame(PveBackupInventoryIssueCode::InvalidField, $inventory->issues[0]->code);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeJobIdProvider(): iterable
    {
        yield 'nul' => ["job\0id"];
        yield 'escape' => ["job\x1Bid"];
        yield 'delete' => ["job\x7Fid"];
        yield 'unicode' => ['jöb'];
    }

    public function testPveNineJobReaderValidatesThePublishedTypedSchemaAndCapabilities(): void
    {
        $reader = new PveBackupJobReader($this->version(9));
        $valid = [
            'id' => 'full',
            'vmid' => '100,200',
            'node' => 'pve',
            'compress' => 'zstd',
            'mode' => 'snapshot',
            'exclude' => '300',
            'mailto' => 'ops@example.test',
            'mailnotification' => 'failure',
            'notification-mode' => 'auto',
            'tmpdir' => '/tmp',
            'dumpdir' => '/dump',
            'script' => '/hook',
            'storage' => 'pbs',
            'pool' => 'pool',
            'notes-template' => '{{vmid}}',
            'pbs-change-detection-mode' => 'data',
            'schedule' => 'daily',
            'comment' => 'full',
            'all' => 1,
            'stdexcludes' => true,
            'quiet' => 0,
            'stop' => false,
            'remove' => 1,
            'protected' => false,
            'enabled' => true,
            'repeat-missed' => 0,
            'pigz' => 0,
            'zstd' => 1,
            'bwlimit' => 0,
            'ionice' => 7,
            'lockwait' => 180,
            'stopwait' => 10,
            'next-run' => 100,
            'exclude-path' => ['/tmp/*'],
            'performance' => (object) ['max-workers' => 16, 'pbs-entries-max' => 100],
            'fleecing' => ['enabled' => 1, 'storage' => 'fast'],
            'prune-backups' => (object) [
                'keep-all' => 0,
                'keep-last' => 1,
                'keep-hourly' => 2,
                'keep-daily' => 3,
                'keep-weekly' => 4,
                'keep-monthly' => 5,
                'keep-yearly' => 6,
            ],
            'future' => ['ignored' => true],
        ];
        $inventory = $reader->read([$valid]);

        self::assertTrue($inventory->isComplete());
        self::assertSame('daily', $inventory->jobs[0]->rawSchedule);
        self::assertTrue($inventory->jobs[0]->enabled);
        self::assertFalse($inventory->jobs[0]->repeatMissed);
        self::assertSame(100, $inventory->jobs[0]->nextRun);
        self::assertSame('100,200', $inventory->jobs[0]->guestIds);
        self::assertTrue($inventory->jobs[0]->allGuests);
        self::assertSame(7, count($inventory->jobs[0]->pruneBackups?->signature() ?? []));

        $invalid = [
            'id' => 'invalid-selected',
            'vmid' => '99,abc',
            'node' => 'pve.test',
            'storage' => 'bad storage',
            'schedule' => "bad\n",
            'comment' => "bad\n",
            'all' => 2,
            'enabled' => 2,
            'repeat-missed' => 2,
            'next-run' => -1,
            'mode' => 'future',
            'compress' => 'future',
            'prune-backups' => 'keep-last=3',
            'maxfiles' => 3,
        ];
        $badInventory = $reader->read([$invalid]);

        self::assertFalse($badInventory->isComplete());
        self::assertContains(PveBackupInventoryIssueCode::UnsupportedCapability, $this->codes($badInventory));
        self::assertCount(13, $badInventory->issues);
        self::assertNull($badInventory->jobs[0]->rawSchedule);
        self::assertNull($badInventory->jobs[0]->guestIds);
        self::assertNull($badInventory->jobs[0]->node);
        self::assertNull($badInventory->jobs[0]->storage);
        self::assertNull($badInventory->jobs[0]->mode);
        self::assertNull($badInventory->jobs[0]->compression);
        self::assertNull($badInventory->jobs[0]->pruneBackups);

        $boundaryFailures = $reader->read([
            ['id' => 'too-long', 'schedule' => str_repeat('a', 129), 'comment' => str_repeat('b', 513)],
            ['id' => 'bad-enums', 'mode' => 'future', 'compress' => 'future'],
        ]);
        self::assertCount(4, $boundaryFailures->issues);
        self::assertNull($boundaryFailures->jobs[0]->rawSchedule);
        self::assertNull($boundaryFailures->jobs[1]->mode);

        $ignored = $reader->read([
            ['id' => 'performance-zero', 'schedule' => 'not-a-locally-validated-calendar', 'performance' => ['max-workers' => 0]],
            ['id' => 'performance-high', 'performance' => ['max-workers' => 257]],
            ['id' => 'pbs-entries-zero', 'performance' => ['pbs-entries-max' => 0]],
            ['id' => 'fleecing-without-storage', 'fleecing' => ['enabled' => 1]],
        ]);
        self::assertTrue($ignored->isComplete());
        self::assertCount(4, $ignored->jobs);
        self::assertSame('not-a-locally-validated-calendar', $ignored->jobs[0]->rawSchedule);
    }

    #[DataProvider('invalidSelectedVmidProvider')]
    public function testSelectedPveNineVmidListUsesThePublishedVmidBounds(mixed $vmids): void
    {
        $inventory = (new PveBackupJobReader($this->version(9)))->read([['id' => 'vmids', 'vmid' => $vmids]]);

        self::assertFalse($inventory->isComplete());
        self::assertNull($inventory->jobs[0]->guestIds);
        self::assertSame('/data/0/vmid', $inventory->issues[0]->field);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSelectedVmidProvider(): iterable
    {
        yield 'wrong type' => [[100]];
        yield 'empty' => [''];
        yield 'below minimum' => ['099'];
        yield 'too many digits' => ['1000000000'];
        yield 'non digit' => ['10a'];
        yield 'empty list member' => ['100,'];
    }

    #[DataProvider('invalidPruneProvider')]
    public function testLegacyPruneReaderRejectsEveryMalformedShape(mixed $prune): void
    {
        $inventory = (new PveBackupJobReader($this->version(8)))->read([[
            'id' => 'job',
            'prune-backups' => $prune,
        ]]);

        self::assertFalse($inventory->isComplete());
        self::assertSame(PveBackupInventoryIssueCode::InvalidField, $inventory->issues[0]->code);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPruneProvider(): iterable
    {
        yield 'string missing value' => ['keep-last='];
        yield 'string non-numeric value' => ['keep-last=abc'];
        yield 'scalar' => [42];
        yield 'list' => [[1]];
        yield 'invalid keep all' => [['keep-all' => 2]];
        yield 'negative retention' => [['keep-daily' => -1]];
    }

    public function testTaskPageReaderAcceptsOfficialRowsAndReportsMalformedRows(): void
    {
        $reader = new PveTaskPageReader();
        $query = PveTaskQuery::active(limit: 10);
        $this->assertFailure(static fn () => $reader->read('pve', $query, 'bad'));
        $this->assertFailure(static fn () => $reader->read('pve', $query, ['not' => 'list']));

        $valid = $this->taskRow();
        $numeric = $this->taskRow(2, '2');
        $numeric['id'] = 2;
        $empty = $this->taskRow(3, '');
        $result = $reader->read('pve', $query, [$valid, (object) $numeric, $empty]);
        self::assertTrue($result->isComplete());
        self::assertCount(3, $result->tasks);
        self::assertSame('2', $result->tasks[1]->upid->id);
        self::assertSame('', $result->tasks[2]->upid->id);

        $invalid = $this->taskRow();
        $invalid['upid'] = 'not-upid';
        $invalid['node'] = "bad\n";
        $invalid['pid'] = -1;
        $invalid['pstart'] = '1';
        $invalid['starttime'] = -1;
        $invalid['type'] = '';
        $invalid['id'] = -1;
        $invalid['user'] = [];
        $invalid['endtime'] = -1;
        $invalid['status'] = "bad\n";
        $partial = $reader->read('pve', $query, ['bad-row', [], $invalid]);
        self::assertFalse($partial->isComplete());
        self::assertSame(0, count($partial->tasks));
        self::assertContains(PveBackupInventoryIssueCode::MissingRequiredField, $this->pageCodes($partial));
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $this->pageCodes($partial));
    }

    #[DataProvider('identityMismatchProvider')]
    public function testTaskPageReaderRejectsEveryIdentityMismatch(string $field, mixed $value): void
    {
        $row = $this->taskRow();
        $row[$field] = $value;
        $result = (new PveTaskPageReader())->read('pve', PveTaskQuery::active(), [$row]);

        self::assertSame([], $result->tasks);
        self::assertSame(PveBackupInventoryIssueCode::IdentityMismatch, $result->issues[0]->code);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function identityMismatchProvider(): iterable
    {
        yield 'node' => ['node', 'other'];
        yield 'pid' => ['pid', 9];
        yield 'pstart' => ['pstart', 9];
        yield 'starttime' => ['starttime', 9];
        yield 'type' => ['type', 'vzdump-other'];
        yield 'id' => ['id', '9'];
        yield 'user' => ['user', 'other@pve'];
    }

    public function testTaskPageRetainsIdentityValidRowsWithInvalidOptionalObservations(): void
    {
        $row = $this->taskRow();
        $row['endtime'] = 'bad';
        $row['status'] = [];
        $page = (new PveTaskPageReader())->read('pve', PveTaskQuery::archive(0, 10), [$row]);

        self::assertCount(1, $page->tasks);
        self::assertNull($page->tasks[0]->endTime);
        self::assertNull($page->tasks[0]->listStatus);
        self::assertCount(2, $page->issues);

        $routeMismatch = $this->taskRow();
        $routeMismatch['node'] = 'other';
        $mismatch = (new PveTaskPageReader())->read('other', PveTaskQuery::active(), [$routeMismatch]);
        self::assertSame(PveBackupInventoryIssueCode::IdentityMismatch, $mismatch->issues[0]->code);

        $withoutOptionals = $this->taskRow();
        unset($withoutOptionals['status']);
        $clean = (new PveTaskPageReader())->read('pve', PveTaskQuery::active(), [$withoutOptionals]);
        self::assertTrue($clean->isComplete());
        self::assertNull($clean->tasks[0]->listStatus);

        $beforeStart = $this->taskRow();
        $beforeStart['endtime'] = 1;
        $invalidEnd = (new PveTaskPageReader())->read('pve', PveTaskQuery::archive(0, 10), [$beforeStart]);
        self::assertFalse($invalidEnd->isComplete());
        self::assertNull($invalidEnd->tasks[0]->endTime);
        self::assertSame('/data/0/endtime', $invalidEnd->issues[0]->field);

        $oversizedStatus = $this->taskRow();
        $oversizedStatus['status'] = str_repeat('A', 256);
        $invalidStatus = (new PveTaskPageReader())->read('pve', PveTaskQuery::active(), [$oversizedStatus]);
        self::assertFalse($invalidStatus->isComplete());
        self::assertNull($invalidStatus->tasks[0]->listStatus);
        self::assertSame('/data/0/status', $invalidStatus->issues[0]->field);
    }

    public function testStatusReaderImplementsVersionedStarttimePstartAndSuccessRules(): void
    {
        $upid = $this->upid();
        $seven = new PveTaskStatusReader($this->version(7));
        $runningRow = $this->statusRow('running');
        $runningRow['starttime'] = (float) $upid->startTime;
        unset($runningRow['pstart']);
        $running = $seven->read('pve', $upid, $runningRow);
        self::assertTrue($running->isComplete());
        self::assertSame(PveTaskLifecycle::Running, $running->lifecycle);
        self::assertNull($running->reportedProcessStart);
        self::assertFalse($running->isSuccessful());

        $ok = (new PveTaskStatusReader($this->version(8)))->read('pve', $upid, $this->statusRow('stopped', 'OK'));
        self::assertTrue($ok->isSuccessful());
        $error = (new PveTaskStatusReader($this->version(9)))->read('pve', $upid, $this->statusRow('stopped', 'ERROR'));
        self::assertTrue($error->isComplete());
        self::assertFalse($error->isSuccessful());

        $numericId = $this->statusRow('running');
        $numericId['id'] = 1;
        self::assertTrue((new PveTaskStatusReader($this->version(8)))
            ->read('pve', $upid, $numericId)->isComplete());
    }

    public function testStatusReaderRejectsInvalidEnvelopesAndMarksMissingFieldsPartial(): void
    {
        $reader = new PveTaskStatusReader($this->version(9));
        $upid = $this->upid();
        $this->assertFailure(static fn () => $reader->read('pve', $upid, []));
        $this->assertFailure(static fn () => $reader->read('pve', $upid, 'bad'));

        $missing = $reader->read('pve', $upid, (object) ['future' => true]);
        self::assertFalse($missing->isComplete());
        self::assertNull($missing->lifecycle);
        self::assertContains(PveBackupInventoryIssueCode::MissingRequiredField, $this->statusCodes($missing));
        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($missing));

        $invalid = $this->statusRow('unknown', '');
        $invalid['upid'] = "bad\n";
        $invalid['node'] = [];
        $invalid['type'] = '';
        $invalid['id'] = -1;
        $invalid['user'] = "bad\n";
        $invalid['pid'] = -1;
        $invalid['starttime'] = 'bad';
        $invalid['pstart'] = -1;
        $invalid['exitstatus'] = "bad\n";
        $partial = $reader->read('pve', $upid, $invalid);
        self::assertFalse($partial->isComplete());
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $this->statusCodes($partial));
        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($partial));
    }

    public function testStatusReaderTreatsUnknownAndInconsistentStatesAsNeverSuccessful(): void
    {
        $reader = new PveTaskStatusReader($this->version(8));
        $upid = $this->upid();
        $runningWithExit = $reader->read('pve', $upid, $this->statusRow('running', 'OK'));
        $stoppedWithoutExit = $reader->read('pve', $upid, $this->statusRow('stopped'));
        $unknown = $reader->read('pve', $upid, $this->statusRow('waiting'));

        self::assertContains(PveBackupInventoryIssueCode::InconsistentTaskStatus, $this->statusCodes($runningWithExit));
        self::assertContains(PveBackupInventoryIssueCode::InconsistentTaskStatus, $this->statusCodes($stoppedWithoutExit));
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $this->statusCodes($unknown));
        self::assertFalse($runningWithExit->isSuccessful());
        self::assertFalse($stoppedWithoutExit->isSuccessful());
        self::assertFalse($unknown->isSuccessful());
    }

    #[DataProvider('invalidPve7StarttimeProvider')]
    public function testPveSevenStarttimeNumberMustBeFiniteIntegralNonNegativeAndIntFit(mixed $starttime): void
    {
        $row = $this->statusRow('running');
        $row['starttime'] = $starttime;
        unset($row['pstart']);
        $status = (new PveTaskStatusReader($this->version(7)))->read('pve', $this->upid(), $row);

        self::assertFalse($status->isComplete());
        self::assertContains(PveBackupInventoryIssueCode::InvalidField, $this->statusCodes($status));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPve7StarttimeProvider(): iterable
    {
        yield 'fraction' => [3.5];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'too large' => [(float) PHP_INT_MAX * 2.0];
    }

    #[DataProvider('statusIdentityMismatchProvider')]
    public function testStatusReaderChecksEveryRouteAndResponseIdentity(string $field, mixed $value, string $routeNode): void
    {
        $row = $this->statusRow('running');
        $row[$field] = $value;
        $status = (new PveTaskStatusReader($this->version(8)))->read($routeNode, $this->upid(), $row);

        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($status));
        self::assertFalse($status->isSuccessful());
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function statusIdentityMismatchProvider(): iterable
    {
        yield 'route node' => ['node', 'pve', 'other'];
        yield 'reported upid' => ['upid', 'UPID:other:00000001:00000002:00000003:vzdump:1:user@pve:', 'pve'];
        yield 'node' => ['node', 'other', 'pve'];
        yield 'type' => ['type', 'other', 'pve'];
        yield 'id' => ['id', '2', 'pve'];
        yield 'user' => ['user', 'other@pve', 'pve'];
        yield 'pid' => ['pid', 9, 'pve'];
        yield 'starttime' => ['starttime', 9, 'pve'];
        yield 'pstart' => ['pstart', 9, 'pve'];
    }

    #[DataProvider('majorVersionProvider')]
    public function testStatusReaderReconstructsTokenAuthenticatedUpidPrincipal(int $major): void
    {
        $upid = $this->tokenUpid();
        $row = $this->statusRowFor($upid, 'stopped', 'OK');
        $row['user'] = 'user@pve';
        $row['tokenid'] = 'hoddmimir';
        if (7 === $major) {
            $row['starttime'] = (float) $upid->startTime;
            unset($row['pstart']);
        }

        $status = (new PveTaskStatusReader($this->version($major)))->read('pve', $upid, $row);

        self::assertTrue($status->isComplete());
        self::assertTrue($status->isSuccessful());
        self::assertSame([], $status->issues);
    }

    public function testStatusReaderKeepsNonTokenUserIdentityBehavior(): void
    {
        $status = (new PveTaskStatusReader($this->version(9)))
            ->read('pve', $this->upid(), $this->statusRow('stopped', 'OK'));

        self::assertTrue($status->isComplete());
        self::assertTrue($status->isSuccessful());
    }

    public function testStatusReaderRejectsTokenIdForANonTokenUpid(): void
    {
        $row = $this->statusRow('stopped', 'OK');
        $row['tokenid'] = 'hoddmimir';

        $status = (new PveTaskStatusReader($this->version(9)))
            ->read('pve', $this->upid(), $row);

        self::assertFalse($status->isComplete());
        self::assertFalse($status->isSuccessful());
        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($status));
    }

    public function testStatusReaderRequiresTokenIdEvenWhenUserReportsTheFullTokenPrincipal(): void
    {
        $upid = $this->tokenUpid();
        $row = $this->statusRowFor($upid, 'stopped', 'OK');

        $status = (new PveTaskStatusReader($this->version(9)))->read('pve', $upid, $row);

        self::assertFalse($status->isComplete());
        self::assertFalse($status->isSuccessful());
        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($status));
    }

    #[DataProvider('tokenIdentityFailureProvider')]
    public function testStatusReaderFailsClosedForMissingMismatchedOrInvalidTokenId(
        string $case,
        string $user,
        mixed $tokenId,
        bool $expectedInvalidField,
    ): void {
        $upid = $this->tokenUpid();
        $row = $this->statusRowFor($upid, 'stopped', 'OK');
        $row['user'] = $user;
        if ('missing' !== $case) {
            $row['tokenid'] = $tokenId;
        }

        $status = (new PveTaskStatusReader($this->version(9)))->read('pve', $upid, $row);

        self::assertFalse($status->isComplete());
        self::assertFalse($status->isSuccessful());
        self::assertContains(PveBackupInventoryIssueCode::IdentityMismatch, $this->statusCodes($status));
        self::assertSame($expectedInvalidField, in_array(
            PveBackupInventoryIssueCode::InvalidField,
            $this->statusCodes($status),
            true,
        ));
    }

    /** @return iterable<string, array{string, string, mixed, bool}> */
    public static function tokenIdentityFailureProvider(): iterable
    {
        yield 'missing tokenid' => ['missing', 'user@pve', null, false];
        yield 'mismatched tokenid' => ['present', 'user@pve', 'other-token', false];
        yield 'empty tokenid' => ['present', 'user@pve', '', true];
        yield 'invalid tokenid characters' => ['present', 'user@pve', 'bad token', true];
        yield 'non-string tokenid' => ['present', 'user@pve', 1, true];
        yield 'invalid token owner' => ['present', 'not-a-principal', 'hoddmimir', true];
    }

    /** @return iterable<string, array{int}> */
    public static function majorVersionProvider(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    /** @return array<string, mixed> */
    private function taskRow(int $pid = 1, string $id = '1'): array
    {
        $upid = PveUpid::parse(sprintf(
            'UPID:pve:%08X:%08X:%08X:vzdump:%s:user@pve:',
            $pid,
            $pid + 1,
            $pid + 2,
            $id,
        ));

        return [
            'upid' => $upid->raw,
            'node' => $upid->node,
            'pid' => $upid->pid,
            'pstart' => $upid->processStart,
            'starttime' => $upid->startTime,
            'type' => $upid->type,
            'id' => $id,
            'user' => $upid->user,
            'status' => 'RUNNING',
        ];
    }

    private function upid(): PveUpid
    {
        return PveUpid::parse('UPID:pve:00000001:00000002:00000003:vzdump:1:user@pve:');
    }

    /** @return array<string, mixed> */
    private function statusRow(string $status, ?string $exitStatus = null): array
    {
        return $this->statusRowFor($this->upid(), $status, $exitStatus);
    }

    /** @return array<string, mixed> */
    private function statusRowFor(PveUpid $upid, string $status, ?string $exitStatus = null): array
    {
        $row = [
            'upid' => $upid->raw,
            'node' => $upid->node,
            'pid' => $upid->pid,
            'pstart' => $upid->processStart,
            'starttime' => $upid->startTime,
            'type' => $upid->type,
            'id' => $upid->id,
            'user' => $upid->user,
            'status' => $status,
        ];
        if (null !== $exitStatus) {
            $row['exitstatus'] = $exitStatus;
        }

        return $row;
    }

    private function tokenUpid(): PveUpid
    {
        return PveUpid::parse('UPID:pve:00000001:00000002:00000003:vzdump:1:user@pve!hoddmimir:');
    }

    private function version(int $major): PveVersion
    {
        return new PveVersion($major, 0, null, $major.'.0', $major.'.0', 'abcdef12');
    }

    private function assertFailure(callable $callable): void
    {
        try {
            $callable();
            self::fail('Expected invalid PVE response failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
    }

    /** @return list<PveBackupInventoryIssueCode> */
    private function codes(\App\Application\Proxmox\Pve\PveBackupJobInventory $inventory): array
    {
        return array_map(static fn ($issue) => $issue->code, $inventory->issues);
    }

    /** @return list<PveBackupInventoryIssueCode> */
    private function pageCodes(\App\Application\Proxmox\Pve\PveTaskPage $page): array
    {
        return array_map(static fn ($issue) => $issue->code, $page->issues);
    }

    /** @return list<PveBackupInventoryIssueCode> */
    private function statusCodes(\App\Application\Proxmox\Pve\PveTaskStatus $status): array
    {
        return array_map(static fn ($issue) => $issue->code, $status->issues);
    }
}
