<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Persistence\MariaDb;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Proxmox\Pve\PveBackupClient;
use App\Application\Proxmox\Pve\PveBackupSubmission;
use App\Application\Proxmox\Pve\PveBackupSubmissionResult;
use App\Application\Proxmox\Pve\PveTaskLogPage;
use App\Application\Proxmox\Pve\PveTaskLogQuery;
use App\Application\Proxmox\Pve\PveTaskPage;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Application\Proxmox\Pve\PveTaskStatus;
use App\Application\Proxmox\Pve\PveTaskStopResult;
use App\Application\Proxmox\Pve\PveUpid;
use App\Application\Proxmox\Pve\PveVersion;
use App\Infrastructure\Persistence\MariaDb\DbalPveBackupClientProvider;
use App\Infrastructure\Proxmox\PveBackup\PveBackupClientFactory;
use App\Infrastructure\Proxmox\PveBackup\PveBackupEndpointConfiguration;
use App\Infrastructure\Proxmox\PveTlsMode;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DbalPveBackupClientProviderTest extends TestCase
{
    public function testMapsRequestConfigurationAndLatestVersionIntoOwnClientFactory(): void
    {
        foreach ([
            $this->row(),
            $this->row(tlsMode: 'sha256_fingerprint', fingerprint: str_repeat("\xAB", 32)),
        ] as $index => $row) {
            $db = $this->createMock(Connection::class);
            $db->expects(self::once())->method('fetchAssociative')->willReturn($row);
            $factory = new CapturingBackupClientFactory();

            $client = (new DbalPveBackupClientProvider($db, $factory))->forRequest(str_repeat('r', 16));

            self::assertSame($factory->client, $client);
            $configuration = $factory->configuration;
            $version = $factory->version;
            self::assertNotNull($configuration);
            self::assertNotNull($version);
            self::assertSame('pve.example.test', $configuration->host);
            self::assertSame(8006, $configuration->port);
            self::assertSame(0 === $index ? PveTlsMode::SystemCa : PveTlsMode::CertificateFingerprint, $configuration->tls->mode);
            self::assertSame(9, $version->major);
            self::assertSame(2, $version->minor);
            self::assertSame(1, $version->patch);
        }
    }

    public function testMissingAndMalformedConfigurationFailsClosed(): void
    {
        $cases = [false, $this->row(port: 'bad'), $this->row(tlsMode: 'invalid'), $this->row(credentialId: 'short')];
        foreach ($cases as $row) {
            $db = $this->createMock(Connection::class);
            $db->method('fetchAssociative')->willReturn($row);
            try {
                (new DbalPveBackupClientProvider($db, new CapturingBackupClientFactory()))->forRequest(str_repeat('r', 16));
                self::fail('Invalid backup client configuration was accepted.');
            } catch (PveBackupApiFailure $failure) {
                self::assertSame(PveBackupApiFailureCode::Configuration, $failure->failureCode);
            }
        }

        $this->expectException(PveBackupApiFailure::class);
        (new DbalPveBackupClientProvider($this->createMock(Connection::class), new CapturingBackupClientFactory()))->forRequest('bad');
    }

    /** @return array<string, mixed> */
    private function row(string $tlsMode = 'system_ca', mixed $port = 8006, ?string $fingerprint = null, string $credentialId = 'cccccccccccccccc'): array
    {
        return [
            'host' => 'pve.example.test', 'port' => $port, 'tls_mode' => $tlsMode,
            'custom_ca_pem' => null, 'sha256_fingerprint' => $fingerprint,
            'credential_id' => $credentialId, 'principal' => 'backup@pve',
            'token_name' => 'hoddmimir', 'secret_envelope' => 'opaque-secret',
            'version_major' => 9, 'version_minor' => 2, 'version_patch' => 1,
            'release_name' => 'bookworm', 'raw_version' => '9.2.1',
        ];
    }
}

final class CapturingBackupClientFactory implements PveBackupClientFactory
{
    public ?PveBackupEndpointConfiguration $configuration = null;
    public ?PveVersion $version = null;
    public BackupProviderClient $client;
    public function __construct() { $this->client = new BackupProviderClient(); }
    public function create(PveBackupEndpointConfiguration $configuration, PveVersion $version): PveBackupClient { $this->configuration = $configuration; $this->version = $version; return $this->client; }
}

final class BackupProviderClient implements PveBackupClient
{
    public function submit(PveBackupSubmission $submission): PveBackupSubmissionResult { throw new \LogicException('unused'); }
    public function taskStatus(PveUpid $upid): PveTaskStatus { throw new \LogicException('unused'); }
    public function taskLog(PveUpid $upid, PveTaskLogQuery $query): PveTaskLogPage { throw new \LogicException('unused'); }
    public function stopTask(PveUpid $upid): PveTaskStopResult { throw new \LogicException('unused'); }
    public function taskPage(string $node, PveTaskQuery $query): PveTaskPage { throw new \LogicException('unused'); }
}
