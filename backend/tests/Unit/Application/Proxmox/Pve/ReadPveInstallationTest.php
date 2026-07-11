<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveGuestResource;
use App\Application\Proxmox\Pve\PveGuestType;
use App\Application\Proxmox\Pve\PveInstallationSnapshot;
use App\Application\Proxmox\Pve\PveInventoryIssue;
use App\Application\Proxmox\Pve\PveInventoryIssueCode;
use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PveNodeResource;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadConnector;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveRequiredPermission;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageResource;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadPveInstallationTest extends TestCase
{
    public function testItReadsAFreshSessionInTheRequiredOrder(): void
    {
        $log = new CallLog();
        $snapshot = (new ReadPveInstallation(new RecordingConnector($log)))->read();

        self::assertSame(['connect', 'version', 'permissions', 'topology', 'resources'], $log->events);
        self::assertSame(9, $snapshot->version->major);
        self::assertSame(2, $snapshot->version->minor);
        self::assertSame(3, $snapshot->version->patch);
        self::assertSame('9.2', $snapshot->version->release);
        self::assertSame('9.2.3', $snapshot->version->version);
        self::assertSame('9000000c', $snapshot->version->repoId);
        self::assertTrue($snapshot->isComplete());
    }

    public function testTypedDtoSemanticsRemainExplicit(): void
    {
        $missing = new PveMissingPermission(PveRequiredPermission::VirtualMachineAudit);
        self::assertSame('/vms', $missing->path());
        self::assertSame('VM.Audit', $missing->privilege());
        self::assertFalse((new PvePermissionAssessment([$missing]))->isComplete());
        self::assertSame('/', PveRequiredPermission::SystemAudit->path());
        self::assertSame('/storage', PveRequiredPermission::DatastoreAudit->path());

        $issue = new PveInventoryIssue(PveInventoryIssueCode::MissingRequiredField, 'qemu', 4, '/data/4/node');
        $topology = new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            1,
            7,
            true,
            [new PveClusterNode('pve-a.test', true, 1, true)],
            [$issue],
        );
        $guest = new PveGuestResource(PveGuestType::Lxc, 42, 'pve-a.test', null, null, null);
        $storage = new PveStorageResource('backup', 'pve-a.test', null, 'backup', null, null, null);
        $inventory = new PveResourceInventory(
            [new PveNodeResource('pve-a.test', null)],
            [$guest],
            [$storage],
            [$issue],
        );
        $snapshot = new PveInstallationSnapshot(
            new PveVersion(7, 4, null, '7.4', '7.4-19', 'repo'),
            new PvePermissionAssessment([]),
            $topology,
            $inventory,
        );

        self::assertFalse($topology->isComplete());
        self::assertFalse($inventory->isComplete());
        self::assertFalse($snapshot->isComplete());
        self::assertFalse((new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [],
            [],
        ))->isComplete());
        self::assertFalse((new PveResourceInventory([], [], [], []))->isComplete());
        self::assertSame('lxc:42', $guest->identity());
        self::assertSame('pve-a.test:backup', $storage->identity());
        self::assertSame(PveClusterMode::Clustered, $topology->mode);
        self::assertSame(PveInventoryIssueCode::DuplicateResource->value, 'duplicate_resource');
        self::assertSame(PveInventoryIssueCode::DuplicateClusterRecord->value, 'duplicate_cluster_record');
        self::assertSame(PveInventoryIssueCode::InvalidTopology->value, 'invalid_topology');
        self::assertSame(PveGuestType::Qemu->value, 'qemu');
    }

    public function testSnapshotCompletenessRequiresConsistentNodeAndPlacementSets(): void
    {
        $version = new PveVersion(9, 2, 3, '9.2', '9.2.3', '9000000c');
        $permissions = new PvePermissionAssessment([]);
        $topology = new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            2,
            9,
            true,
            [new PveClusterNode('b.test', true, 2, false), new PveClusterNode('a.test', true, 1, true)],
            [],
        );
        $nodes = [new PveNodeResource('a.test', 'online'), new PveNodeResource('b.test', 'online')];
        $guest = new PveGuestResource(PveGuestType::Qemu, 1, 'a.test', null, null, null);
        $storage = new PveStorageResource('backup', 'b.test', null, 'backup', null, null, null);

        self::assertTrue((new PveInstallationSnapshot(
            $version,
            $permissions,
            $topology,
            new PveResourceInventory($nodes, [$guest], [$storage], []),
        ))->isComplete());
        self::assertFalse((new PveInstallationSnapshot(
            $version,
            $permissions,
            $topology,
            new PveResourceInventory([new PveNodeResource('c.test', 'online')], [], [], []),
        ))->isComplete());
        self::assertFalse((new PveInstallationSnapshot(
            $version,
            $permissions,
            $topology,
            new PveResourceInventory($nodes, [new PveGuestResource(PveGuestType::Qemu, 2, 'unknown.test', null, null, null)], [], []),
        ))->isComplete());
        self::assertFalse((new PveInstallationSnapshot(
            $version,
            $permissions,
            $topology,
            new PveResourceInventory($nodes, [], [new PveStorageResource('backup', 'unknown.test', null, 'backup', null, null, null)], []),
        ))->isComplete());
    }

    public function testTopologyCompletenessRequiresTypedClusterAndStandaloneInvariants(): void
    {
        $node = new PveClusterNode('a.test', true, 1, true);
        $clustered = static fn (
            ?string $name,
            ?int $declaredNodes,
            ?int $configurationVersion,
            ?bool $quorate,
        ): PveClusterTopology => new PveClusterTopology(
            PveClusterMode::Clustered,
            $name,
            $declaredNodes,
            $configurationVersion,
            $quorate,
            [$node],
            [],
        );

        self::assertTrue($clustered('forest', 1, 7, true)->isComplete());
        self::assertFalse($clustered(null, 1, 7, true)->isComplete());
        self::assertFalse($clustered('', 1, 7, true)->isComplete());
        self::assertFalse($clustered('forest', 2, 7, true)->isComplete());
        self::assertFalse($clustered('forest', 1, null, true)->isComplete());
        self::assertFalse($clustered('forest', 1, 0, true)->isComplete());
        self::assertFalse($clustered('forest', 1, 7, false)->isComplete());

        $standalone = static fn (PveClusterNode ...$nodes): PveClusterTopology => new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            array_values($nodes),
            [],
        );
        self::assertTrue($standalone(new PveClusterNode('solo.test', true, 0, true))->isComplete());
        self::assertFalse($standalone(new PveClusterNode('solo.test', true, 0, false))->isComplete());
        self::assertFalse($standalone(new PveClusterNode('solo.test', true, 1, true))->isComplete());
        self::assertFalse($standalone(
            new PveClusterNode('solo.test', true, 0, true),
            new PveClusterNode('other.test', true, 1, false),
        )->isComplete());
    }

    #[DataProvider('failureProvider')]
    public function testFailuresExposeOnlyStableSafeCodesAndMessages(PveReadFailureCode $code, string $message): void
    {
        $failure = PveReadFailure::for($code);

        self::assertSame($code, $failure->failureCode);
        self::assertSame($message, $failure->getMessage());
    }

    /** @return iterable<string, array{PveReadFailureCode, string}> */
    public static function failureProvider(): iterable
    {
        yield 'credential' => [PveReadFailureCode::CredentialUnavailable, 'The PVE credential is unavailable.'];
        yield 'transport' => [PveReadFailureCode::Transport, 'The PVE API transport failed.'];
        yield 'authentication' => [PveReadFailureCode::Authentication, 'The PVE API rejected the credential.'];
        yield 'permission' => [PveReadFailureCode::PermissionDenied, 'The PVE API denied the request.'];
        yield 'rate limit' => [PveReadFailureCode::RateLimited, 'The PVE API rate limit was reached.'];
        yield 'unavailable' => [PveReadFailureCode::RemoteUnavailable, 'The PVE API is temporarily unavailable.'];
        yield 'not found' => [PveReadFailureCode::NotFound, 'The requested PVE API resource does not exist.'];
        yield 'status' => [PveReadFailureCode::HttpStatus, 'The PVE API returned an unexpected status.'];
        yield 'envelope' => [PveReadFailureCode::InvalidEnvelope, 'The PVE API returned an invalid JSON envelope.'];
        yield 'response' => [PveReadFailureCode::InvalidResponse, 'The PVE API response is incomplete.'];
        yield 'version' => [PveReadFailureCode::UnsupportedVersion, 'The PVE major version is unsupported.'];
    }
}

/** @internal */
final readonly class RecordingConnector implements PveReadConnector
{
    public function __construct(private CallLog $log)
    {
    }

    public function connect(): PveReadClient
    {
        $this->log->events[] = 'connect';
        return new RecordingClient($this->log);
    }
}

/** @internal */
final readonly class RecordingClient implements PveReadClient
{
    public function __construct(private CallLog $log)
    {
    }

    public function version(): PveVersion
    {
        $this->log->events[] = 'version';
        return new PveVersion(9, 2, 3, '9.2', '9.2.3', '9000000c');
    }

    public function permissions(): PvePermissionAssessment
    {
        $this->log->events[] = 'permissions';
        return new PvePermissionAssessment([]);
    }

    public function topology(): PveClusterTopology
    {
        $this->log->events[] = 'topology';
        return new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode('pve-a.test', true, 0, true)],
            [],
        );
    }

    public function resources(): PveResourceInventory
    {
        $this->log->events[] = 'resources';
        return new PveResourceInventory([new PveNodeResource('pve-a.test', 'online')], [], [], []);
    }

    public function storageConfigurations(): PveStorageConfigurationSet
    {
        throw new \LogicException('Not used by this slice.');
    }

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet
    {
        throw new \LogicException('Not used by this slice.');
    }

    public function backupJobs(): \App\Application\Proxmox\Pve\PveBackupJobInventory
    {
        throw new \LogicException('Not used by this slice.');
    }

    public function backupTaskPage(
        string $node,
        \App\Application\Proxmox\Pve\PveTaskQuery $query,
    ): \App\Application\Proxmox\Pve\PveTaskPage {
        throw new \LogicException('Not used by this slice.');
    }

    public function backupTaskStatus(
        string $node,
        \App\Application\Proxmox\Pve\PveUpid $upid,
    ): \App\Application\Proxmox\Pve\PveTaskStatus {
        throw new \LogicException('Not used by this slice.');
    }
}

/** @internal */
final class CallLog
{
    /** @var list<string> */
    public array $events = [];
}
