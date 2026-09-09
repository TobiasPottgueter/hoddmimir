<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshClaim;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshEndpoint;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshSubject;
use App\Application\Backup\Execution\ExecutorEvidenceLeaseOwnershipLost;
use App\Application\Backup\Execution\ExecutorPermissionProjection;
use App\Application\Backup\Execution\PveExecutorAclEntry;
use App\Application\Backup\Execution\PveExecutorIdentityValidator;
use App\Application\Backup\Execution\PveExecutorPermissionMatrixPath;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExecutorEvidenceRefreshValueObjectsTest extends TestCase
{
    private const string A = "aaaaaaaaaaaaaaaa";
    private const string B = "bbbbbbbbbbbbbbbb";

    public function testValidClaimSubjectAndProjectionExposeStableSecretFreeBindings(): void
    {
        $first = new ExecutorEvidenceRefreshEndpoint(self::A, 10);
        $second = new ExecutorEvidenceRefreshEndpoint(self::B, 10);
        $claim = new ExecutorEvidenceRefreshClaim(
            self::A, 1, 2, 3, self::A, self::B, 4,
            new DateTimeImmutable('2026-07-16T08:00:00+00:00'), [$first, $second],
        );
        self::assertSame([$first, $second], $claim->endpoints);

        $target = new ExecutorEvidenceRefreshSubject(
            self::A, self::A, self::A, self::A, self::A, 'store-1', null, null,
        );
        self::assertSame('/vms', $target->guestPath());
        self::assertSame('/storage/store-1', $target->storagePath());
        self::assertSame(self::A.self::A.\str_repeat("\0", 16), $target->cursor());

        $guest = new ExecutorEvidenceRefreshSubject(
            self::A, self::A, self::A, self::A, self::A, 'store-1', self::B, 101,
        );
        self::assertSame('/vms/101', $guest->guestPath());
        self::assertSame(self::A.self::A.self::B, $guest->cursor());

        $projection = new ExecutorPermissionProjection($guest, self::B, 1, 2, 3, true, false);
        self::assertFalse($projection->authorized());
    }

    public function testRefreshFailuresExposeOnlyAStableCodeAndLeaseLossIsASeparateControlFlow(): void
    {
        $failure = ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable);
        self::assertSame(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable, $failure->failureCode);
        self::assertSame('Executor permission evidence refresh failed.', $failure->getMessage());
        self::assertNull($failure->getPrevious());

        $leaseLoss = new ExecutorEvidenceLeaseOwnershipLost();
        self::assertSame('Executor permission evidence lease ownership was lost.', $leaseLoss->getMessage());
    }

    #[DataProvider('invalidEndpointProvider')]
    public function testEndpointRejectsInvalidValues(string $id, int $priority): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExecutorEvidenceRefreshEndpoint($id, $priority);
    }

    /** @return iterable<string, array{string, int}> */
    public static function invalidEndpointProvider(): iterable
    {
        yield 'identifier' => ['short', 1];
        yield 'negative priority' => [self::A, -1];
        yield 'oversized priority' => [self::A, 65_536];
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidClaimProvider')]
    public function testClaimRejectsInvalidScalarValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
        new ExecutorEvidenceRefreshClaim(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidClaimProvider(): iterable
    {
        $valid = [self::A, 1, 1, 1, self::A, self::B, 1, new DateTimeImmutable('2026-07-16T08:00:00Z'), [new ExecutorEvidenceRefreshEndpoint(self::A, 1)]];
        foreach ([0, 4, 5] as $index) {
            $case = $valid;
            $case[$index] = 'short';
            yield 'bad binary '.$index => [$case];
        }
        foreach ([1, 2, 3, 6] as $index) {
            $case = $valid;
            $case[$index] = 0;
            yield 'bad revision '.$index => [$case];
        }
        $case = $valid;
        $case[7] = new DateTimeImmutable('2026-07-16T08:00:00+01:00');
        yield 'non UTC lease' => [$case];
        $case = $valid;
        $case[8] = [];
        yield 'empty endpoints' => [$case];
        $case = $valid;
        $case[8] = \array_map(
            static fn (int $id): ExecutorEvidenceRefreshEndpoint => new ExecutorEvidenceRefreshEndpoint(
                \str_repeat("\0", 12).\pack('N', $id),
                1,
            ),
            \range(1, ExecutorEvidenceRefreshClaim::MAXIMUM_ENDPOINTS + 1),
        );
        yield 'too many endpoints' => [$case];
        $case = $valid;
        $case[8] = ['wrong'];
        yield 'wrong endpoint type' => [$case];
        $case = $valid;
        $case[8] = [new ExecutorEvidenceRefreshEndpoint(self::A, 1), new ExecutorEvidenceRefreshEndpoint(self::A, 2)];
        yield 'duplicate endpoint' => [$case];
        $case = $valid;
        $case[8] = [new ExecutorEvidenceRefreshEndpoint(self::A, 2), new ExecutorEvidenceRefreshEndpoint(self::B, 1)];
        yield 'priority order' => [$case];
        $case = $valid;
        $case[8] = [new ExecutorEvidenceRefreshEndpoint(self::B, 1), new ExecutorEvidenceRefreshEndpoint(self::A, 1)];
        yield 'identifier order' => [$case];
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidSubjectProvider')]
    public function testSubjectRejectsInvalidValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
        new ExecutorEvidenceRefreshSubject(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidSubjectProvider(): iterable
    {
        $valid = [self::A, self::A, self::A, self::A, self::A, 'store-1', self::B, 101];
        foreach (range(0, 4) as $index) {
            $case = $valid;
            $case[$index] = 'bad';
            yield 'bad required id '.$index => [$case];
        }
        foreach ([[null, 101], [self::B, null], ['bad', 101], [self::B, 0], [self::B, 1_000_000_000]] as $pair) {
            $case = $valid;
            [$case[6], $case[7]] = $pair;
            yield 'guest pair '.\json_encode($pair) => [$case];
        }
        $case = $valid;
        $case[5] = '1invalid';
        yield 'storage id' => [$case];
    }

    #[DataProvider('invalidPathProvider')]
    public function testMatrixPathRejectsInvalidPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PveExecutorPermissionMatrixPath($path, []);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPathProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'relative' => ['vms'];
        yield 'trailing slash' => ['/vms/'];
        yield 'double slash' => ['/vms//1'];
        yield 'space' => ['/vms/bad value'];
        yield 'too long' => ['/'.\str_repeat('a', 2048)];
    }

    public function testMatrixPathAndPrivilegesAreStrictlyBounded(): void
    {
        self::assertSame('/', (new PveExecutorPermissionMatrixPath('/', ['VM.Backup' => 0]))->path);
        foreach ([['' => 1], ['bad privilege' => 1], ['VM.Backup' => 2], \array_fill_keys(range(1, 257), 1)] as $privileges) {
            try {
                /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
                new PveExecutorPermissionMatrixPath('/vms', $privileges);
                self::fail('Expected invalid privileges.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidAclProvider')]
    public function testAclRejectsInvalidValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
        new PveExecutorAclEntry(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidAclProvider(): iterable
    {
        yield 'type' => [['/vms', 'role', 'user@pve', 'NoAccess', true]];
        yield 'empty identity' => [['/vms', 'user', '', 'NoAccess', true]];
        yield 'long identity' => [['/vms', 'group', 'A'.\str_repeat('a', 255), 'NoAccess', true]];
        yield 'spaced identity' => [['/vms', 'user', 'bad identity', 'NoAccess', true]];
        yield 'role' => [['/vms', 'user', 'user@pve', 'bad role', true]];
    }

    public function testAclExposesStrictFields(): void
    {
        $acl = new PveExecutorAclEntry('/vms', 'group', 'Guests', 'PVEAuditor', false);
        self::assertSame('PVEAuditor', $acl->roleId);
        self::assertFalse($acl->propagate);
    }

    public function testExecutorIdentitiesUseBoundedVisiblePveGrammar(): void
    {
        self::assertTrue(PveExecutorIdentityValidator::owner('service+backup@pve'));
        self::assertTrue(PveExecutorIdentityValidator::token('service+backup@pve!token-1'));
        self::assertTrue(PveExecutorIdentityValidator::group('Backup_Group-1'));
        foreach ([
            ['', 'token'],
            ["user@pve!bad\0token", 'token'],
            ['user@pve!x', 'token'],
            ['user@pve!'.\str_repeat('a', 65), 'token'],
            [\str_repeat('a', 61).'@pve', 'owner'],
            ['usér@pve', 'owner'],
            ['bad group', 'group'],
            ['', 'group'],
        ] as [$identity, $kind]) {
            self::assertFalse(PveExecutorIdentityValidator::{$kind}($identity));
        }
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidSnapshotProvider')]
    public function testSnapshotRejectsInvalidScalarValues(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
        new PveExecutorPermissionSnapshot(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidSnapshotProvider(): iterable
    {
        $valid = [self::A, self::B, 1, 1, 1, 9, 'user@pve!token', 'user@pve', [], []];
        foreach ([0, 1] as $index) {
            $case = $valid;
            $case[$index] = 'bad';
            yield 'binary '.$index => [$case];
        }
        foreach ([2, 3, 4] as $index) {
            $case = $valid;
            $case[$index] = 0;
            yield 'revision '.$index => [$case];
        }
        foreach ([6 => 'bad', 7 => 'bad'] as $index => $value) {
            $case = $valid;
            $case[$index] = $value;
            yield 'identity '.$index => [$case];
        }
        $case = $valid;
        $case[5] = 10;
        yield 'major' => [$case];
        $case = $valid;
        $case[6] = 'other@pve!token';
        yield 'owner mismatch' => [$case];
    }

    public function testSnapshotRejectsMalformedDuplicateAndOversizedCollections(): void
    {
        $matrix = new PveExecutorPermissionMatrixPath('/vms', []);
        $acl = new PveExecutorAclEntry('/vms', 'user', 'user@pve', 'PVEAuditor', true);
        foreach ([
            [['wrong'], []],
            [[$matrix, $matrix], []],
            [[], ['wrong']],
            [[], [$acl, $acl]],
            [\array_fill(0, PveExecutorPermissionSnapshot::MAXIMUM_MATRIX_PATHS + 1, $matrix), []],
            [[], \array_fill(0, PveExecutorPermissionSnapshot::MAXIMUM_ACL_ENTRIES + 1, $acl)],
        ] as [$matrices, $acls]) {
            try {
                /** @var list<PveExecutorPermissionMatrixPath> $matrices Intentionally malformed at runtime. */
                /** @var list<PveExecutorAclEntry> $acls Intentionally malformed at runtime. */
                new PveExecutorPermissionSnapshot(self::A, self::B, 1, 1, 1, 9, 'user@pve!token', 'user@pve', $matrices, $acls);
                self::fail('Expected malformed snapshot rejection.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('invalidProjectionProvider')]
    public function testProjectionRejectsInvalidRevisionBindings(string $endpoint, int $connection, int $backup, int $scan): void
    {
        $subject = new ExecutorEvidenceRefreshSubject(
            self::A, self::A, self::A, self::A, self::A, 'store-1', null, null,
        );
        $this->expectException(InvalidArgumentException::class);
        new ExecutorPermissionProjection($subject, $endpoint, $connection, $backup, $scan, false, false);
    }

    /** @return iterable<string, array{string, int, int, int}> */
    public static function invalidProjectionProvider(): iterable
    {
        yield 'endpoint' => ['bad', 1, 1, 1];
        yield 'connection' => [self::A, 0, 1, 1];
        yield 'backup' => [self::A, 1, 0, 1];
        yield 'scan' => [self::A, 1, 1, 0];
    }
}
