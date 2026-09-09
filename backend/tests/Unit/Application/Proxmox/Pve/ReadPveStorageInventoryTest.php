<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pve;

use App\Application\Proxmox\Pve\PveClusterMode;
use App\Application\Proxmox\Pve\PveClusterNode;
use App\Application\Proxmox\Pve\PveClusterTopology;
use App\Application\Proxmox\Pve\PveMissingPermission;
use App\Application\Proxmox\Pve\PveNodeStorageStatus;
use App\Application\Proxmox\Pve\PveNodeStorageStatusSet;
use App\Application\Proxmox\Pve\PvePermissionAssessment;
use App\Application\Proxmox\Pve\PveReadClient;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveRequiredPermission;
use App\Application\Proxmox\Pve\PveResourceInventory;
use App\Application\Proxmox\Pve\PveStorageCapacity;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageConfiguration;
use App\Application\Proxmox\Pve\PveStorageConfigurationSet;
use App\Application\Proxmox\Pve\PveStorageContentSet;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Application\Proxmox\Pve\PveVersion;
use App\Application\Proxmox\Pve\ReadPveStorageInventory;
use App\Tests\Fakes\FrozenClock;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ReadPveStorageInventoryTest extends TestCase
{
    public function testCompleteReadUsesTheExactStartNodeEndSequenceAndInjectedUtcTime(): void
    {
        $configuration = $this->configuration();
        $client = new StorageRecordingClient(
            [$configuration, $configuration],
            [
                'node-a.test' => new PveNodeStorageStatusSet('node-a.test', [
                    $this->nodeStatus('node-a.test', 'local', 'dir', ['backup', 'iso'], false),
                    $this->nodeStatus('node-a.test', 'pbs', 'pbs', ['backup'], true),
                ], []),
                'node-b.test' => new PveNodeStorageStatusSet('node-b.test', [
                    $this->nodeStatus('node-b.test', 'pbs', 'pbs', ['backup'], true),
                ], []),
            ],
        );

        $snapshot = (new ReadPveStorageInventory(new FrozenClock(
            new DateTimeImmutable('2026-07-10T14:00:00+02:00'),
        )))->read($client, new PvePermissionAssessment([]), $this->topology('node-a.test', 'node-b.test'));

        self::assertTrue($snapshot->isAuthoritative());
        self::assertSame([], $snapshot->issues);
        self::assertCount(3, $snapshot->observations);
        self::assertSame([
            'storage-configurations',
            'node-backup-storages:node-a.test',
            'node-backup-storages:node-b.test',
            'storage-configurations',
        ], $client->calls);
        self::assertSame('2026-07-10T12:00:00+00:00', $snapshot->observations[0]->observedAt->format('c'));
        self::assertSame(100, $snapshot->observations[0]->capacity?->totalBytes);
    }

    public function testStorageAclCoverageIsASeparateExactPrerequisite(): void
    {
        $missingDatastore = $this->readWith(
            new PvePermissionAssessment([new PveMissingPermission(PveRequiredPermission::DatastoreAudit)]),
        );
        self::assertFalse($missingDatastore->isAuthoritative());
        self::assertContains(PveStorageIssueCode::MissingPermissionCoverage, $this->codes($missingDatastore->issues));

        $missingVmOnly = $this->readWith(
            new PvePermissionAssessment([new PveMissingPermission(PveRequiredPermission::VirtualMachineAudit)]),
        );
        self::assertTrue($missingVmOnly->isAuthoritative());
        self::assertNotContains(PveStorageIssueCode::MissingPermissionCoverage, $this->codes($missingVmOnly->issues));
    }

    public function testIncompleteTopologyStaysPartialAndEachDistinctUsableNodeIsReadOnce(): void
    {
        $configuration = $this->configurationFor([
            $this->definition('pbs', 'pbs', ['backup'], null, false, true),
        ]);
        $client = new StorageRecordingClient(
            [$configuration, $configuration],
            ['node-a.test' => new PveNodeStorageStatusSet('node-a.test', [
                $this->nodeStatus('node-a.test', 'pbs', 'pbs', ['backup'], true),
            ], [])],
        );
        $topology = new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            4,
            1,
            true,
            [
                new PveClusterNode('node-a.test', true, 1, true),
                new PveClusterNode('node-a.test', true, 1, true),
                new PveClusterNode('', true, 2, false),
            ],
            [],
        );

        $snapshot = $this->reader()->read($client, new PvePermissionAssessment([]), $topology);

        self::assertFalse($snapshot->isAuthoritative());
        self::assertContains(PveStorageIssueCode::IncompleteTopology, $this->codes($snapshot->issues));
        self::assertSame([
            'storage-configurations',
            'node-backup-storages:node-a.test',
            'storage-configurations',
        ], $client->calls);
    }

    public function testNodeFailureKeepsOtherPositiveObservationsAndStillPerformsTheEndRead(): void
    {
        $configuration = $this->configuration();
        $client = new StorageRecordingClient(
            [$configuration, $configuration],
            [
                'node-a.test' => new PveNodeStorageStatusSet('node-a.test', [
                    $this->nodeStatus('node-a.test', 'local', 'dir', ['backup', 'iso'], false),
                    $this->nodeStatus('node-a.test', 'pbs', 'pbs', ['backup'], true),
                ], []),
                'node-b.test' => PveReadFailure::for(PveReadFailureCode::RemoteUnavailable),
            ],
        );

        $snapshot = $this->reader()->read(
            $client,
            new PvePermissionAssessment([]),
            $this->topology('node-a.test', 'node-b.test'),
        );

        self::assertFalse($snapshot->isAuthoritative());
        self::assertCount(2, $snapshot->observations);
        self::assertSame([PveStorageIssueCode::NodeReadFailed], $this->codes($snapshot->issues));
        self::assertSame('storage-configurations', $client->calls[3]);
    }

    public function testConfigurationFailuresNeverDiscardSafeNodeObservations(): void
    {
        $configuration = $this->configuration();
        $nodeSets = $this->completeNodeSets();

        $startFailure = new StorageRecordingClient(
            [PveReadFailure::for(PveReadFailureCode::RemoteUnavailable), $configuration],
            $nodeSets,
        );
        $startSnapshot = $this->reader()->read(
            $startFailure,
            new PvePermissionAssessment([]),
            $this->topology('node-a.test', 'node-b.test'),
        );
        self::assertNull($startSnapshot->startConfiguration);
        self::assertCount(3, $startSnapshot->observations);
        self::assertSame([PveStorageIssueCode::ConfigurationReadFailed], $this->codes($startSnapshot->issues));

        $endFailure = new StorageRecordingClient(
            [$configuration, PveReadFailure::for(PveReadFailureCode::RemoteUnavailable)],
            $nodeSets,
        );
        $endSnapshot = $this->reader()->read(
            $endFailure,
            new PvePermissionAssessment([]),
            $this->topology('node-a.test', 'node-b.test'),
        );
        self::assertNull($endSnapshot->endConfiguration);
        self::assertCount(3, $endSnapshot->observations);
        self::assertSame([PveStorageIssueCode::ConfigurationReadFailed], $this->codes($endSnapshot->issues));
    }

    public function testDigestAndNormalizedVisibleDefinitionRacesAreIndependentFailures(): void
    {
        $start = $this->configuration();
        $digestChanged = $this->configuration('changed-digest');
        $digestClient = new StorageRecordingClient([$start, $digestChanged], $this->completeNodeSets());
        $digestSnapshot = $this->reader()->read(
            $digestClient,
            new PvePermissionAssessment([]),
            $this->topology('node-a.test', 'node-b.test'),
        );
        self::assertContains(PveStorageIssueCode::ConfigurationChanged, $this->codes($digestSnapshot->issues));
        self::assertNotContains(PveStorageIssueCode::VisibleConfigurationChanged, $this->codes($digestSnapshot->issues));

        $visibleChanged = $this->configurationFor([
            $this->definition('local', 'dir', ['backup', 'iso'], ['node-a.test'], false, false),
            $this->definition('pbs', 'future-pbs', ['backup'], null, false, true),
            $this->definition('disabled', 'dir', ['backup'], null, true, false),
            $this->definition('non-backup', 'dir', ['backup-archive'], null, false, false),
        ]);
        $visibleClient = new StorageRecordingClient([$start, $visibleChanged], $this->completeNodeSets());
        $visibleSnapshot = $this->reader()->read(
            $visibleClient,
            new PvePermissionAssessment([]),
            $this->topology('node-a.test', 'node-b.test'),
        );
        self::assertContains(PveStorageIssueCode::VisibleConfigurationChanged, $this->codes($visibleSnapshot->issues));
        self::assertNotContains(PveStorageIssueCode::ConfigurationChanged, $this->codes($visibleSnapshot->issues));
    }

    public function testExpectedMissingUnexpectedAndContradictoryStatusesStayPartial(): void
    {
        $configuration = $this->configurationFor([
            $this->definition('missing', 'dir', ['backup'], null, false, false),
            $this->definition('conflict', 'dir', ['backup', 'iso'], null, false, false),
            $this->definition('disabled', 'dir', ['backup'], null, true, false),
            $this->definition('restricted', 'dir', ['backup'], ['other.test'], false, false),
            $this->definition('substring', 'dir', ['backup-archive'], null, false, false),
        ]);
        $enabledIssue = new PveStorageIssue(
            PveStorageIssueCode::ConfigurationStatusConflict,
            '/nodes/node.test/storage',
            '/data/0/enabled',
            'conflict',
            'node.test',
        );
        $client = new StorageRecordingClient([$configuration, $configuration], [
            'node.test' => new PveNodeStorageStatusSet('node.test', [
                $this->nodeStatus('node.test', 'conflict', 'future', ['backup'], true, false),
                $this->nodeStatus('node.test', 'unexpected', 'future', ['backup'], false),
            ], [$enabledIssue]),
        ]);

        $snapshot = $this->reader()->read(
            $client,
            new PvePermissionAssessment([]),
            $this->standaloneTopology('node.test'),
        );
        $codes = $this->codes($snapshot->issues);

        self::assertContains(PveStorageIssueCode::MissingExpectedObservation, $codes);
        self::assertContains(PveStorageIssueCode::UnexpectedObservation, $codes);
        self::assertSame(4, $this->countCode($snapshot->issues, PveStorageIssueCode::ConfigurationStatusConflict));
        self::assertSame(1, $this->countIssueField($snapshot->issues, '/data/0/enabled'));
        self::assertCount(2, $snapshot->observations);
    }

    public function testTypedReaderIssuesAndMismatchedNodeIdentityArePropagated(): void
    {
        $configuration = $this->configurationFor([
            $this->definition('pbs', 'pbs', ['backup'], null, false, true),
        ]);
        $readerIssue = new PveStorageIssue(
            PveStorageIssueCode::InvalidCapacity,
            '/nodes/node.test/storage',
            '/data/0/capacity',
            'pbs',
            'node.test',
        );
        $client = new StorageRecordingClient([$configuration, $configuration], [
            'node.test' => new PveNodeStorageStatusSet('different.test', [
                $this->nodeStatus('node.test', 'pbs', 'pbs', ['backup'], true),
            ], [$readerIssue]),
        ]);

        $snapshot = $this->reader()->read(
            $client,
            new PvePermissionAssessment([]),
            $this->standaloneTopology('node.test'),
        );

        self::assertContains(PveStorageIssueCode::InvalidCapacity, $this->codes($snapshot->issues));
        self::assertContains(PveStorageIssueCode::ConfigurationStatusConflict, $this->codes($snapshot->issues));
        self::assertCount(1, $snapshot->observations);
    }

    public function testNullDigestAndReaderDigestIssuesCannotBecomeAuthoritative(): void
    {
        $definition = $this->definition('pbs', 'pbs', ['backup'], null, false, true);
        $withoutIssue = new PveStorageConfigurationSet(null, [$definition], []);
        $client = new StorageRecordingClient([$withoutIssue, $withoutIssue], [
            'node.test' => new PveNodeStorageStatusSet('node.test', [
                $this->nodeStatus('node.test', 'pbs', 'pbs', ['backup'], true),
            ], []),
        ]);
        $snapshot = $this->reader()->read(
            $client,
            new PvePermissionAssessment([]),
            $this->standaloneTopology('node.test'),
        );
        self::assertSame(2, $this->countCode($snapshot->issues, PveStorageIssueCode::MissingConfigurationDigest));

        $digestIssue = new PveStorageIssue(
            PveStorageIssueCode::InconsistentConfigurationDigest,
            '/storage',
            '/data/*/digest',
        );
        $withIssue = new PveStorageConfigurationSet(null, [$definition], [$digestIssue]);
        $issueClient = new StorageRecordingClient([$withIssue, $withIssue], [
            'node.test' => new PveNodeStorageStatusSet('node.test', [
                $this->nodeStatus('node.test', 'pbs', 'pbs', ['backup'], true),
            ], []),
        ]);
        $issueSnapshot = $this->reader()->read(
            $issueClient,
            new PvePermissionAssessment([]),
            $this->standaloneTopology('node.test'),
        );
        self::assertSame([
            PveStorageIssueCode::InconsistentConfigurationDigest,
            PveStorageIssueCode::InconsistentConfigurationDigest,
        ], $this->codes($issueSnapshot->issues));
    }

    public function testNoTopologyNodesStillPerformsBothConfigurationReads(): void
    {
        $configuration = $this->configurationFor([
            $this->definition('pbs', 'pbs', ['backup'], null, false, true),
        ]);
        $client = new StorageRecordingClient([$configuration, $configuration], []);
        $topology = new PveClusterTopology(PveClusterMode::Standalone, null, null, null, null, [], []);

        $snapshot = $this->reader()->read($client, new PvePermissionAssessment([]), $topology);

        self::assertFalse($snapshot->isAuthoritative());
        self::assertSame(['storage-configurations', 'storage-configurations'], $client->calls);
        self::assertSame([PveStorageIssueCode::IncompleteTopology], $this->codes($snapshot->issues));
    }

    private function readWith(PvePermissionAssessment $permissions): \App\Application\Proxmox\Pve\PveStorageInventorySnapshot
    {
        $configuration = $this->configuration();
        return $this->reader()->read(
            new StorageRecordingClient([$configuration, $configuration], $this->completeNodeSets()),
            $permissions,
            $this->topology('node-a.test', 'node-b.test'),
        );
    }

    private function reader(): ReadPveStorageInventory
    {
        return new ReadPveStorageInventory(new FrozenClock(new DateTimeImmutable('2026-07-10T12:00:00Z')));
    }

    private function configuration(string $digest = 'digest'): PveStorageConfigurationSet
    {
        return $this->configurationFor([
            $this->definition('local', 'dir', ['backup', 'iso'], ['node-a.test'], false, false),
            $this->definition('pbs', 'pbs', ['backup'], null, false, true),
            $this->definition('disabled', 'dir', ['backup'], null, true, false),
            $this->definition('non-backup', 'dir', ['backup-archive'], null, false, false),
        ], $digest);
    }

    /** @param list<PveStorageConfiguration> $definitions */
    private function configurationFor(array $definitions, string $digest = 'digest'): PveStorageConfigurationSet
    {
        return new PveStorageConfigurationSet($digest, $definitions, []);
    }

    /**
     * @param list<string>      $content
     * @param null|list<string> $nodes
     */
    private function definition(
        string $storage,
        string $type,
        array $content,
        ?array $nodes,
        bool $disabled,
        bool $shared,
    ): PveStorageConfiguration {
        return new PveStorageConfiguration(
            $storage,
            $type,
            new PveStorageContentSet($content),
            $nodes,
            $disabled,
            $shared,
            null,
        );
    }

    /** @param list<string> $content */
    private function nodeStatus(
        string $node,
        string $storage,
        string $type,
        array $content,
        bool $shared,
        bool $enabled = true,
    ): PveNodeStorageStatus {
        return new PveNodeStorageStatus(
            $node,
            $storage,
            $type,
            new PveStorageContentSet($content),
            $enabled,
            true,
            $shared,
            PveStorageCapacityState::Fresh,
            new PveStorageCapacity(100, 20, 70),
        );
    }

    /** @return array<string, PveNodeStorageStatusSet|PveReadFailure> */
    private function completeNodeSets(): array
    {
        return [
            'node-a.test' => new PveNodeStorageStatusSet('node-a.test', [
                $this->nodeStatus('node-a.test', 'local', 'dir', ['backup', 'iso'], false),
                $this->nodeStatus('node-a.test', 'pbs', 'pbs', ['backup'], true),
            ], []),
            'node-b.test' => new PveNodeStorageStatusSet('node-b.test', [
                $this->nodeStatus('node-b.test', 'pbs', 'pbs', ['backup'], true),
            ], []),
        ];
    }

    private function topology(string ...$nodes): PveClusterTopology
    {
        $clusterNodes = [];
        $nodeId = 1;
        foreach ($nodes as $node) {
            $clusterNodes[] = new PveClusterNode($node, true, $nodeId, 1 === $nodeId);
            ++$nodeId;
        }

        return new PveClusterTopology(
            PveClusterMode::Clustered,
            'forest',
            count($nodes),
            1,
            true,
            $clusterNodes,
            [],
        );
    }

    private function standaloneTopology(string $node): PveClusterTopology
    {
        return new PveClusterTopology(
            PveClusterMode::Standalone,
            null,
            null,
            null,
            null,
            [new PveClusterNode($node, true, 0, true)],
            [],
        );
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<PveStorageIssueCode>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (PveStorageIssue $issue): PveStorageIssueCode => $issue->code, $issues);
    }

    /** @param list<PveStorageIssue> $issues */
    private function countCode(array $issues, PveStorageIssueCode $code): int
    {
        $count = 0;
        foreach ($issues as $issue) {
            if ($code === $issue->code) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<PveStorageIssue> $issues */
    private function countIssueField(array $issues, string $field): int
    {
        $count = 0;
        foreach ($issues as $issue) {
            if ($field === $issue->field) {
                ++$count;
            }
        }

        return $count;
    }
}

/** @internal */
final class StorageRecordingClient implements PveReadClient
{
    /** @var list<PveStorageConfigurationSet|PveReadFailure> */
    private array $configurations;

    /** @var array<string, PveNodeStorageStatusSet|PveReadFailure> */
    private array $nodes;

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param list<PveStorageConfigurationSet|PveReadFailure>        $configurations
     * @param array<string, PveNodeStorageStatusSet|PveReadFailure> $nodes
     */
    public function __construct(array $configurations, array $nodes)
    {
        $this->configurations = $configurations;
        $this->nodes = $nodes;
    }

    public function version(): PveVersion
    {
        throw new LogicException('Not used by the storage slice.');
    }

    public function permissions(): PvePermissionAssessment
    {
        throw new LogicException('Not used by the storage slice.');
    }

    public function topology(): PveClusterTopology
    {
        throw new LogicException('Not used by the storage slice.');
    }

    public function resources(): PveResourceInventory
    {
        throw new LogicException('Not used by the storage slice.');
    }

    public function storageConfigurations(): PveStorageConfigurationSet
    {
        $this->calls[] = 'storage-configurations';
        $configuration = array_shift($this->configurations);
        if ($configuration instanceof PveReadFailure) {
            throw $configuration;
        }
        if (!$configuration instanceof PveStorageConfigurationSet) {
            throw new LogicException('Missing storage configuration result.');
        }

        return $configuration;
    }

    public function nodeBackupStorages(string $node): PveNodeStorageStatusSet
    {
        $this->calls[] = 'node-backup-storages:'.$node;
        $statusSet = $this->nodes[$node] ?? null;
        if ($statusSet instanceof PveReadFailure) {
            throw $statusSet;
        }
        if (!$statusSet instanceof PveNodeStorageStatusSet) {
            throw new LogicException('Missing node storage result.');
        }

        return $statusSet;
    }

    public function backupJobs(): \App\Application\Proxmox\Pve\PveBackupJobInventory
    {
        throw new LogicException('Not used by the storage slice.');
    }

    public function backupTaskPage(
        string $node,
        \App\Application\Proxmox\Pve\PveTaskQuery $query,
    ): \App\Application\Proxmox\Pve\PveTaskPage {
        throw new LogicException('Not used by the storage slice.');
    }

    public function backupTaskStatus(
        string $node,
        \App\Application\Proxmox\Pve\PveUpid $upid,
    ): \App\Application\Proxmox\Pve\PveTaskStatus {
        throw new LogicException('Not used by the storage slice.');
    }
}
