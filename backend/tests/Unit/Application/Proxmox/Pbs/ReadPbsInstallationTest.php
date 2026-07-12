<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsDatastoreBackendType;
use App\Application\Proxmox\Pbs\PbsDatastoreCapacity;
use App\Application\Proxmox\Pbs\PbsDatastoreConfigurationSnapshot;
use App\Application\Proxmox\Pbs\PbsDatastoreDefinition;
use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsDatastoreScanScope;
use App\Application\Proxmox\Pbs\PbsEffectivePermission;
use App\Application\Proxmox\Pbs\PbsInstanceIdentity;
use App\Application\Proxmox\Pbs\PbsInventoryIssueCode;
use App\Application\Proxmox\Pbs\PbsMountStatus;
use App\Application\Proxmox\Pbs\PbsNodeStatus;
use App\Application\Proxmox\Pbs\PbsReadClient;
use App\Application\Proxmox\Pbs\PbsReadConnector;
use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Application\Proxmox\Pbs\PbsVersion;
use App\Application\Proxmox\Pbs\ReadPbsInstallation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadPbsInstallationTest extends TestCase
{
    #[DataProvider('invalidMaximumDatastoreFanoutProvider')]
    public function testMaximumDatastoreFanoutMustRemainWithinOperationalBounds(int $maximum): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ReadPbsInstallation(
            new FixturePbsConnector(FixturePbsClient::pbs3()),
            PbsDatastoreScanScope::installationWide(),
            $maximum,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMaximumDatastoreFanoutProvider(): iterable
    {
        yield 'below minimum' => [0];
        yield 'above maximum' => [1025];
    }

    public function testInstallationWidePbs3ReadHasExactBoundedOrder(): void
    {
        $client = FixturePbsClient::pbs3();
        $snapshot = (new ReadPbsInstallation(new FixturePbsConnector($client), PbsDatastoreScanScope::installationWide()))->read();
        self::assertTrue($snapshot->isComplete());
        self::assertNull($snapshot->instanceIdentity);
        self::assertSame([
            'version', 'nodes', 'permission:/system/status', 'node-status:pbs', 'permission:/datastore',
            'config', 'datastores', 'status:store_a', 'status:store_b', 'config',
        ], $client->calls);
    }

    public function testExplicitPbs42ReadUsesPerStorePermissionsAndIdentity(): void
    {
        $client = FixturePbsClient::pbs42();
        $scope = PbsDatastoreScanScope::explicit([new PbsDatastoreId('store_b')]);
        $snapshot = (new ReadPbsInstallation(new FixturePbsConnector($client), $scope))->read();
        self::assertTrue($snapshot->isComplete());
        self::assertNotNull($snapshot->instanceIdentity);
        self::assertCount(1, $snapshot->datastores);
        self::assertSame([
            'version', 'nodes', 'permission:/system/status', 'node-status:pbs', 'identity:pbs',
            'permission:/datastore/store_b', 'config', 'datastores', 'status:store_b', 'config',
        ], $client->calls);
    }

    public function testInvalidNodeCardinalityFailsBeforeInventoryReads(): void
    {
        $client = FixturePbsClient::pbs3();
        $client->nodes = [];
        try {
            (new ReadPbsInstallation(new FixturePbsConnector($client), PbsDatastoreScanScope::installationWide()))->read();
            self::fail('Expected invalid node set.');
        } catch (PbsReadFailure $failure) {
            self::assertSame(PbsReadFailureCode::InvalidResponse, $failure->failureCode);
            self::assertSame(['version', 'nodes'], $client->calls);
        }
    }

    public function testPartialReadsRetainPositiveObservationsAndNeverAuthorizeDeletion(): void
    {
        $client = FixturePbsClient::pbs42();
        $client->permissions['/system/status'] = new PbsEffectivePermission('/system/status', []);
        $client->permissions['/datastore'] = new PbsEffectivePermission('/datastore', ['Datastore.Audit' => false]);
        $client->failures = ['node-status:pbs', 'identity:pbs', 'status:store_b'];
        $client->definitions[0] = new PbsDatastoreDefinition(
            new PbsDatastoreId('store_a'),
            PbsDatastoreBackendType::Filesystem,
            PbsMountStatus::NotMounted,
            null,
        );
        $client->endConfig = new PbsDatastoreConfigurationSnapshot(str_repeat('c', 64), [new PbsDatastoreId('store_a'), new PbsDatastoreId('store_b')]);

        $snapshot = (new ReadPbsInstallation(new FixturePbsConnector($client), PbsDatastoreScanScope::installationWide()))->read();
        self::assertFalse($snapshot->permitsDeletionDecisions());
        $codes = array_map(static fn ($issue) => $issue->code, $snapshot->issues);
        self::assertContains(PbsInventoryIssueCode::MissingSystemStatusPermission, $codes);
        self::assertContains(PbsInventoryIssueCode::NodeStatusReadFailed, $codes);
        self::assertContains(PbsInventoryIssueCode::IdentityReadFailed, $codes);
        self::assertContains(PbsInventoryIssueCode::MissingDatastorePropagation, $codes);
        self::assertNotContains(PbsInventoryIssueCode::UnavailableDatastore, $codes);
        self::assertContains(PbsInventoryIssueCode::ConfigurationChanged, $codes);
    }

    public function testReadFailuresAndScopeMismatchesBecomeTypedIssues(): void
    {
        $client = FixturePbsClient::pbs3();
        $client->permissions['/datastore/store_a'] = new PbsEffectivePermission('/datastore/store_a', []);
        $client->failures = ['permission:/system/status', 'config:first', 'datastores'];
        $scope = PbsDatastoreScanScope::explicit([new PbsDatastoreId('store_a')]);
        $snapshot = (new ReadPbsInstallation(new FixturePbsConnector($client), $scope))->read();
        $codes = array_map(static fn ($issue) => $issue->code, $snapshot->issues);
        self::assertContains(PbsInventoryIssueCode::MissingSystemStatusPermission, $codes);
        self::assertContains(PbsInventoryIssueCode::MissingDatastorePermission, $codes);
        self::assertContains(PbsInventoryIssueCode::ConfigurationReadFailed, $codes);
        self::assertContains(PbsInventoryIssueCode::DatastoreListReadFailed, $codes);
        self::assertContains(PbsInventoryIssueCode::MissingDatastore, $codes);
        self::assertFalse($snapshot->isComplete());
    }

    public function testUnexpectedMissingAndStatusFailureAreSeparated(): void
    {
        $client = FixturePbsClient::pbs3();
        $client->definitions = [
            new PbsDatastoreDefinition(new PbsDatastoreId('store_a'), PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null),
            new PbsDatastoreDefinition(new PbsDatastoreId('extra_x'), PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null),
        ];
        $client->failures = ['status:store_a'];
        $snapshot = (new ReadPbsInstallation(new FixturePbsConnector($client), PbsDatastoreScanScope::installationWide()))->read();
        $codes = array_map(static fn ($issue) => $issue->code, $snapshot->issues);
        self::assertContains(PbsInventoryIssueCode::UnexpectedDatastore, $codes);
        self::assertContains(PbsInventoryIssueCode::MissingDatastore, $codes);
        self::assertContains(PbsInventoryIssueCode::DatastoreStatusReadFailed, $codes);
    }

    public function testInstallationWidePermissionReadFailurePreventsStatusReads(): void
    {
        $client = FixturePbsClient::pbs3();
        $client->failures = ['permission:/datastore', 'config:first'];

        $snapshot = (new ReadPbsInstallation(
            new FixturePbsConnector($client),
            PbsDatastoreScanScope::installationWide(),
        ))->read();

        $codes = array_map(static fn ($issue) => $issue->code, $snapshot->issues);
        self::assertContains(PbsInventoryIssueCode::MissingDatastorePermission, $codes);
        self::assertNotContains('status:store_a', $client->calls);
        self::assertNotContains('status:store_b', $client->calls);
        self::assertFalse($snapshot->isComplete());
    }

    public function testExplicitStoreMustExistInBothConfigurationSnapshots(): void
    {
        $client = FixturePbsClient::pbs3();
        $onlyOtherStore = new PbsDatastoreConfigurationSnapshot(
            str_repeat('a', 64),
            [new PbsDatastoreId('store_a')],
        );
        $client->startConfig = $onlyOtherStore;
        $client->endConfig = $onlyOtherStore;

        $snapshot = (new ReadPbsInstallation(
            new FixturePbsConnector($client),
            PbsDatastoreScanScope::explicit([new PbsDatastoreId('store_b')]),
        ))->read();

        self::assertCount(1, $snapshot->datastores);
        self::assertCount(1, $snapshot->capacities);
        self::assertSame(
            [PbsInventoryIssueCode::MissingDatastoreConfiguration],
            array_map(static fn ($issue) => $issue->code, $snapshot->issues),
        );
        self::assertFalse($snapshot->isComplete());
        self::assertFalse($snapshot->permitsDeletionDecisions());
    }

    #[DataProvider('fanoutBoundaryProvider')]
    public function testFanoutBoundaryIsAllOrNothingAndNeverTruncatesStatusReads(
        int $datastoreCount,
        int $expectedStatusReads,
    ): void {
        $client = FixturePbsClient::pbs42();
        $ids = [];
        $definitions = [];
        for ($index = 0; $index < $datastoreCount; ++$index) {
            $id = new PbsDatastoreId(sprintf('store_%03d', $index));
            $ids[] = $id;
            $definitions[] = new PbsDatastoreDefinition(
                $id,
                PbsDatastoreBackendType::Filesystem,
                PbsMountStatus::Mounted,
                null,
            );
        }
        $configuration = new PbsDatastoreConfigurationSnapshot(str_repeat('d', 64), $ids);
        $client->startConfig = $configuration;
        $client->endConfig = $configuration;
        $client->definitions = $definitions;

        $snapshot = (new ReadPbsInstallation(
            new FixturePbsConnector($client),
            PbsDatastoreScanScope::installationWide(),
            128,
        ))->read();

        $statusCalls = array_values(array_filter(
            $client->calls,
            static fn (string $call): bool => str_starts_with($call, 'status:'),
        ));
        self::assertCount($expectedStatusReads, $statusCalls);
        self::assertCount($expectedStatusReads, $snapshot->capacities);
        self::assertCount($datastoreCount, $snapshot->datastores);
        self::assertSame(
            129 === $datastoreCount,
            in_array(
                PbsInventoryIssueCode::DatastoreFanoutExceeded,
                array_map(static fn ($issue) => $issue->code, $snapshot->issues),
                true,
            ),
        );
    }

    /** @return iterable<string, array{int, int}> */
    public static function fanoutBoundaryProvider(): iterable
    {
        yield 'below limit' => [127, 127];
        yield 'at limit' => [128, 128];
        yield 'above limit is not truncated' => [129, 0];
    }
}

/** @internal */
final class FixturePbsConnector implements PbsReadConnector
{
    public function __construct(private readonly PbsReadClient $client) {}
    public function connect(): PbsReadClient { return $this->client; }
}

/** @internal */
final class FixturePbsClient implements PbsReadClient
{
    /** @var list<string> */ public array $calls = [];
    /** @var list<string> */ public array $nodes = ['pbs'];
    /** @var array<string, PbsEffectivePermission> */ public array $permissions;
    /** @var list<PbsDatastoreDefinition> */ public array $definitions;
    /** @var list<string> */ public array $failures = [];
    public PbsDatastoreConfigurationSnapshot $startConfig;
    public PbsDatastoreConfigurationSnapshot $endConfig;
    private int $configReads = 0;

    private function __construct(private readonly PbsVersion $pbsVersion)
    {
        $a = new PbsDatastoreId('store_a');
        $b = new PbsDatastoreId('store_b');
        $this->permissions = [
            '/system/status' => new PbsEffectivePermission('/system/status', ['Sys.Audit' => false]),
            '/datastore' => new PbsEffectivePermission('/datastore', ['Datastore.Audit' => true]),
            '/datastore/store_a' => new PbsEffectivePermission('/datastore/store_a', ['Datastore.Audit' => false]),
            '/datastore/store_b' => new PbsEffectivePermission('/datastore/store_b', ['Datastore.Audit' => false]),
        ];
        $this->definitions = [
            new PbsDatastoreDefinition($a, PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null),
            new PbsDatastoreDefinition($b, 4 === $pbsVersion->major ? PbsDatastoreBackendType::S3 : PbsDatastoreBackendType::Filesystem, PbsMountStatus::Mounted, null),
        ];
        $this->startConfig = new PbsDatastoreConfigurationSnapshot(str_repeat('a', 64), [$a, $b]);
        $this->endConfig = $this->startConfig;
    }

    public static function pbs3(): self { return new self(new PbsVersion(3, 4, 4, '3.4.4', '1', 'abcdef12')); }
    public static function pbs42(): self { return new self(new PbsVersion(4, 2, 2, '4.2.2', '1', 'abcdef12')); }

    public function version(): PbsVersion { $this->calls[] = 'version'; return $this->pbsVersion; }
    public function nodeNames(): array { $this->calls[] = 'nodes'; return $this->nodes; }
    public function permission(string $path): PbsEffectivePermission
    {
        $key = 'permission:'.$path; $this->calls[] = $key; $this->fail($key);
        return $this->permissions[$path] ?? new PbsEffectivePermission($path, []);
    }
    public function nodeStatus(string $node): PbsNodeStatus
    {
        $key = 'node-status:'.$node; $this->calls[] = $key; $this->fail($key);
        return new PbsNodeStatus($node, 1, 2, 1, 10, 2, 8);
    }
    public function instanceIdentity(string $node): PbsInstanceIdentity
    {
        $key = 'identity:'.$node; $this->calls[] = $key; $this->fail($key);
        return new PbsInstanceIdentity(str_repeat('a', 32));
    }
    public function datastoreConfigurations(): PbsDatastoreConfigurationSnapshot
    {
        ++$this->configReads; $this->calls[] = 'config';
        $this->fail(1 === $this->configReads ? 'config:first' : 'config:second');
        return 1 === $this->configReads ? $this->startConfig : $this->endConfig;
    }
    public function datastores(): array { $this->calls[] = 'datastores'; $this->fail('datastores'); return $this->definitions; }
    public function datastoreStatus(PbsDatastoreId $id, PbsDatastoreBackendType $backendType): PbsDatastoreCapacity
    {
        $key = 'status:'.$id->value; $this->calls[] = $key; $this->fail($key);
        return new PbsDatastoreCapacity($id, $backendType, 100, 20, 80);
    }
    private function fail(string $key): void
    {
        if (in_array($key, $this->failures, true)) { throw PbsReadFailure::for(PbsReadFailureCode::Transport); }
    }
}
