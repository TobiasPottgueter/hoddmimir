<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Execution;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshSubject;
use App\Application\Backup\Execution\ProjectExecutorPermissionEvidence;
use App\Application\Backup\Execution\PveExecutorAclEntry;
use App\Application\Backup\Execution\PveExecutorPermissionMatrixPath;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectExecutorPermissionEvidenceTest extends TestCase
{
    private const string ID = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";
    private const string ENDPOINT = "\x10\x0f\x0e\x0d\x0c\x0b\x0a\x09\x08\x07\x06\x05\x04\x03\x02\x01";

    #[DataProvider('majorProvider')]
    public function testSanitizedMajorFixtureGrantsExactZeroAndPropagatedSubjects(int $major): void
    {
        $snapshot = $this->fixture($major);
        $exact = $this->project($snapshot, $this->subject($major * 100_000 + 1));
        self::assertTrue($exact->vmBackupAuthorized);
        self::assertTrue($exact->datastoreAllocateAuthorized);
        self::assertTrue($exact->authorized());
        self::assertSame(self::ENDPOINT, $exact->endpointId);
        self::assertSame(4, $exact->connectionRevision);
        self::assertSame(5, $exact->backupCredentialRevision);
        self::assertSame(6, $exact->scanCredentialRevision);

        self::assertFalse($this->project($snapshot, $this->subject($major * 100_000 + 2))->vmBackupAuthorized);
        $inherited = $this->project($snapshot, $this->subject($major * 100_000 + 3, 'other-store'));
        self::assertTrue($inherited->authorized());
    }

    public function testSnapshotCannotAuthorizeASubjectFromAnotherConnection(): void
    {
        $subject = new ExecutorEvidenceRefreshSubject(
            self::ENDPOINT, self::ID, self::ID, self::ID, self::ID, 'lab-backup', self::ID, 101,
        );
        $this->expectException(InvalidArgumentException::class);
        $this->project($this->snapshot($this->grantingMatrix()), $subject);
    }

    /** @return iterable<string, array{int}> */
    public static function majorProvider(): iterable
    {
        yield 'PVE 7' => [7];
        yield 'PVE 8' => [8];
        yield 'PVE 9' => [9];
    }

    public function testExactMatrixPathWithoutPrivilegeIsNegativeAndZeroNeverPropagates(): void
    {
        $snapshot = $this->snapshot([
            new PveExecutorPermissionMatrixPath('/vms', ['VM.Backup' => 0]),
            new PveExecutorPermissionMatrixPath('/vms/101', ['VM.Audit' => 1]),
            new PveExecutorPermissionMatrixPath('/storage', ['Datastore.AllocateSpace' => 1]),
        ]);
        self::assertFalse($this->project($snapshot, $this->subject(101))->vmBackupAuthorized);
        self::assertFalse($this->project($snapshot, $this->subject(102))->vmBackupAuthorized);
    }

    public function testNearestDeeperMatrixRowBlocksAnOlderGrantingAncestor(): void
    {
        $snapshot = $this->snapshot([
            new PveExecutorPermissionMatrixPath('/', ['VM.Backup' => 1, 'Datastore.AllocateSpace' => 1]),
            new PveExecutorPermissionMatrixPath('/vms', ['VM.Audit' => 1]),
        ]);
        self::assertFalse($this->project($snapshot, $this->subject(101))->vmBackupAuthorized);
        self::assertTrue($this->project($snapshot, $this->subject(101))->datastoreAllocateAuthorized);
    }

    public function testMissingMatrixAncestorFailsClosed(): void
    {
        $snapshot = $this->snapshot([
            new PveExecutorPermissionMatrixPath('/storage/lab-backup', ['Datastore.AllocateSpace' => 0]),
        ]);
        self::assertFalse($this->project($snapshot, $this->subject(101))->vmBackupAuthorized);
    }

    #[DataProvider('relevantAclProvider')]
    public function testRelevantTokenOwnerAndUnknownGroupOverridesBlockInheritance(string $type, string $identity, string $role): void
    {
        $snapshot = $this->snapshot(
            $this->grantingMatrix(),
            [new PveExecutorAclEntry('/vms/101', $type, $identity, $role, true)],
        );
        self::assertFalse($this->project($snapshot, $this->subject(101))->vmBackupAuthorized);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function relevantAclProvider(): iterable
    {
        yield 'exact backup token' => ['token', 'backup@pve!hoddmimir', 'NoAccess'];
        yield 'owning user' => ['user', 'backup@pve', 'NoAccess'];
        yield 'group without membership evidence' => ['group', 'AnyGroup', 'NoAccess'];
        yield 'ordinary deeper role is also an override' => ['token', 'backup@pve!hoddmimir', 'PVEAuditor'];
    }

    public function testForeignIdentitiesAndNonPropagatingAncestorDoNotBlockButExactNonPropagatingAclDoes(): void
    {
        $foreign = $this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/vms/101', 'token', 'foreign@pve!token', 'NoAccess', true),
            new PveExecutorAclEntry('/vms/101', 'user', 'foreign@pve', 'NoAccess', true),
            new PveExecutorAclEntry('/vms/intermediate', 'token', 'backup@pve!hoddmimir', 'NoAccess', true),
            new PveExecutorAclEntry('/vms', 'token', 'backup@pve!hoddmimir', 'HoddmimirBackup', true),
        ]);
        self::assertTrue($this->project($foreign, $this->subject(101))->vmBackupAuthorized);

        $nonPropagating = $this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/vms', 'token', 'backup@pve!hoddmimir', 'NoAccess', false),
        ]);
        self::assertTrue($this->project($nonPropagating, $this->subject(101))->vmBackupAuthorized);

        $exact = $this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/vms/101', 'token', 'backup@pve!hoddmimir', 'NoAccess', false),
        ]);
        self::assertFalse($this->project($exact, $this->subject(101))->vmBackupAuthorized);
    }

    public function testRelevantPoolOverrideBlocksOnlyInheritedProof(): void
    {
        $pool = new PveExecutorAclEntry('/pool/restricted', 'group', 'UnknownGroup', 'NoAccess', true);
        $inherited = $this->snapshot($this->grantingMatrix(), [$pool]);
        $projection = $this->project($inherited, $this->subject(101));
        self::assertFalse($projection->vmBackupAuthorized);
        self::assertFalse($projection->datastoreAllocateAuthorized);

        $exact = $this->snapshot([
            new PveExecutorPermissionMatrixPath('/vms/101', ['VM.Backup' => 0]),
            new PveExecutorPermissionMatrixPath('/storage/lab-backup', ['Datastore.AllocateSpace' => 0]),
        ], [$pool]);
        self::assertTrue($this->project($exact, $this->subject(101))->authorized());

        $foreign = $this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/pool/restricted', 'user', 'foreign@pve', 'NoAccess', true),
        ]);
        self::assertTrue($this->project($foreign, $this->subject(101))->authorized());
    }

    public function testTargetLevelVmFamilyRequiresUniformPropagatingProof(): void
    {
        $target = $this->targetSubject();
        self::assertTrue($this->project($this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/vms', 'token', 'backup@pve!hoddmimir', 'HoddmimirBackup', true),
        ]), $target)->authorized());

        self::assertFalse($this->project($this->snapshot([
            new PveExecutorPermissionMatrixPath('/vms', ['VM.Backup' => 0]),
            new PveExecutorPermissionMatrixPath('/storage/lab-backup', ['Datastore.AllocateSpace' => 0]),
        ]), $target)->vmBackupAuthorized);

        self::assertFalse($this->project($this->snapshot([
            ...$this->grantingMatrix(),
            new PveExecutorPermissionMatrixPath('/vms/101', ['VM.Audit' => 1]),
        ]), $target)->vmBackupAuthorized);

        self::assertFalse($this->project($this->snapshot($this->grantingMatrix(), [
            new PveExecutorAclEntry('/vms/101', 'group', 'UnknownGroup', 'NoAccess', false),
        ]), $target)->vmBackupAuthorized);
    }

    /** @param list<PveExecutorPermissionMatrixPath> $matrix
     *  @param list<PveExecutorAclEntry> $acl
     */
    private function snapshot(array $matrix, array $acl = []): PveExecutorPermissionSnapshot
    {
        return new PveExecutorPermissionSnapshot(
            self::ID,
            self::ENDPOINT,
            4,
            5,
            6,
            9,
            'backup@pve!hoddmimir',
            'backup@pve',
            $matrix,
            $acl,
        );
    }

    /** @return list<PveExecutorPermissionMatrixPath> */
    private function grantingMatrix(): array
    {
        return [
            new PveExecutorPermissionMatrixPath('/vms', ['VM.Backup' => 1]),
            new PveExecutorPermissionMatrixPath('/storage', ['Datastore.AllocateSpace' => 1]),
        ];
    }

    private function subject(int $vmid, string $storage = 'lab-backup'): ExecutorEvidenceRefreshSubject
    {
        return new ExecutorEvidenceRefreshSubject(
            self::ID, self::ID, self::ID, self::ID, self::ID, $storage, self::ID, $vmid,
        );
    }

    private function targetSubject(): ExecutorEvidenceRefreshSubject
    {
        return new ExecutorEvidenceRefreshSubject(
            self::ID, self::ID, self::ID, self::ID, self::ID, 'lab-backup', null, null,
        );
    }

    private function project(
        PveExecutorPermissionSnapshot $snapshot,
        ExecutorEvidenceRefreshSubject $subject,
    ): \App\Application\Backup\Execution\ExecutorPermissionProjection {
        return (new ProjectExecutorPermissionEvidence())->project($snapshot, $subject);
    }

    private function fixture(int $major): PveExecutorPermissionSnapshot
    {
        $path = \dirname(__DIR__, 4).'/Fixtures/Proxmox/Pve/'.$major.'/executor-permissions.json';
        /**
         * @var array{
         *   major: int,
         *   backupTokenIdentity: string,
         *   backupOwnerIdentity: string,
         *   backupPermissions: array{data: array<string, array<string, 0|1>>},
         *   scanAcl: array{data: list<array{path: string, type: string, ugid: string, roleid: string, propagate: 0|1}>}
         * } $document
         */
        $document = \json_decode((string) \file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $matrix = [];
        foreach ($document['backupPermissions']['data'] as $permissionPath => $privileges) {
            $matrix[] = new PveExecutorPermissionMatrixPath($permissionPath, $privileges);
        }
        $acl = [];
        foreach ($document['scanAcl']['data'] as $entry) {
            $acl[] = new PveExecutorAclEntry(
                $entry['path'], $entry['type'], $entry['ugid'], $entry['roleid'], 1 === $entry['propagate'],
            );
        }

        return new PveExecutorPermissionSnapshot(
            self::ID,
            self::ENDPOINT,
            4,
            5,
            6,
            $document['major'],
            $document['backupTokenIdentity'],
            $document['backupOwnerIdentity'],
            $matrix,
            $acl,
        );
    }
}
