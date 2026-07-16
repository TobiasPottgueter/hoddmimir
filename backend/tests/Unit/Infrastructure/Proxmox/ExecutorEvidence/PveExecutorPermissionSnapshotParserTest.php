<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Backup\Execution\PveExecutorPermissionMatrixPath;
use App\Application\Backup\Execution\PveExecutorPermissionSnapshot;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorEvidenceEndpointConfiguration;
use App\Infrastructure\Proxmox\ExecutorEvidence\PveExecutorPermissionSnapshotParser;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveExecutorPermissionSnapshotParserTest extends TestCase
{
    private const string CONNECTION = 'connection-id-01';
    private const string ENDPOINT = 'endpoint-id-0001';

    public function testParserAcceptsObjectMatrixListAclAndUnknownFields(): void
    {
        $matrix = (object) [
            '/vms' => (object) ['VM.Backup' => 1],
            '/storage/store-1' => (object) ['Datastore.AllocateSpace' => 0],
        ];
        $acl = [(object) [
            'path' => '/vms', 'type' => 'token', 'ugid' => 'backup@pve!hoddmimir',
            'roleid' => 'HoddmimirBackup', 'propagate' => 1, 'unknown' => 'tolerated',
        ]];
        $snapshot = (new PveExecutorPermissionSnapshotParser())->parse($this->configuration(), $matrix, $acl);
        self::assertCount(2, $snapshot->backupPermissionMatrix);
        self::assertCount(1, $snapshot->scanAcl);
        self::assertTrue($snapshot->scanAcl[0]->propagate);
    }

    public function testEmptyJsonObjectMatrixAndEmptyPrivilegeObjectAreValidNegativeEvidence(): void
    {
        $parser = new PveExecutorPermissionSnapshotParser();
        self::assertSame([], $parser->parse($this->configuration(), (object) [], [])->backupPermissionMatrix);
        $snapshot = $parser->parse($this->configuration(), (object) ['/vms' => (object) []], []);
        self::assertCount(1, $snapshot->backupPermissionMatrix);
        self::assertSame([], $snapshot->backupPermissionMatrix[0]->privileges);
    }

    #[DataProvider('invalidShapeProvider')]
    public function testMalformedOrUnboundedShapesFailWithOnlySanitizedCode(mixed $matrix, mixed $acl): void
    {
        try {
            (new PveExecutorPermissionSnapshotParser())->parse($this->configuration(), $matrix, $acl);
            self::fail('Expected invalid response.');
        } catch (ExecutorEvidenceRefreshFailure $failure) {
            self::assertSame(ExecutorEvidenceRefreshFailureCode::InvalidResponse, $failure->failureCode);
            self::assertNull($failure->getPrevious());
        }
    }

    /** @return iterable<string, array{mixed, mixed}> */
    public static function invalidShapeProvider(): iterable
    {
        $validMatrix = ['/vms' => ['VM.Backup' => 1]];
        $validAcl = [];
        yield 'matrix scalar' => ['bad', $validAcl];
        yield 'matrix list' => [[['VM.Backup' => 1]], $validAcl];
        yield 'matrix bad path key' => [[1 => ['VM.Backup' => 1]], $validAcl];
        yield 'matrix privilege object required' => [['/vms' => 'bad'], $validAcl];
        yield 'matrix privilege list' => [['/vms' => [1]], $validAcl];
        yield 'matrix privilege value' => [['/vms' => ['VM.Backup' => 2]], $validAcl];
        yield 'matrix privilege string' => [['/vms' => ['VM.Backup' => '1']], $validAcl];
        yield 'matrix privilege bool' => [['/vms' => ['VM.Backup' => true]], $validAcl];
        yield 'matrix invalid trailing slash' => [['/vms/' => ['VM.Backup' => 1]], $validAcl];
        yield 'matrix bound' => [\array_fill_keys(\array_map(static fn (int $i): string => '/vms/'.$i, range(1, PveExecutorPermissionSnapshot::MAXIMUM_MATRIX_PATHS + 1)), []), $validAcl];
        yield 'acl scalar' => [$validMatrix, 'bad'];
        yield 'acl associative' => [$validMatrix, ['path' => '/vms']];
        yield 'acl row scalar' => [$validMatrix, ['bad']];
        yield 'acl path' => [$validMatrix, [['path' => 1, 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => 1]]];
        yield 'acl type' => [$validMatrix, [['path' => '/vms', 'type' => 1, 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => 1]]];
        yield 'acl identity' => [$validMatrix, [['path' => '/vms', 'type' => 'user', 'ugid' => 1, 'roleid' => 'PVEAuditor', 'propagate' => 1]]];
        yield 'acl role' => [$validMatrix, [['path' => '/vms', 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 1, 'propagate' => 1]]];
        yield 'acl propagate' => [$validMatrix, [['path' => '/vms', 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => 2]]];
        yield 'acl propagate string' => [$validMatrix, [['path' => '/vms', 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => '1']]];
        yield 'acl propagate bool' => [$validMatrix, [['path' => '/vms', 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => true]]];
        $row = ['path' => '/vms', 'type' => 'user', 'ugid' => 'user@pve', 'roleid' => 'PVEAuditor', 'propagate' => 0];
        yield 'acl duplicate' => [$validMatrix, [$row, $row]];
        yield 'acl bound' => [$validMatrix, \array_fill(0, PveExecutorPermissionSnapshot::MAXIMUM_ACL_ENTRIES + 1, $row)];
    }

    public function testExactParserBoundsAreAcceptedForUniqueMatrixAndAclRows(): void
    {
        $matrix = [];
        $acl = [];
        for ($index = 1; $index <= PveExecutorPermissionSnapshot::MAXIMUM_MATRIX_PATHS; ++$index) {
            $matrix['/vms/'.$index] = ['VM.Backup' => $index % 2];
            $acl[] = [
                'path' => '/vms/'.$index, 'type' => 'group', 'ugid' => 'Group'.$index,
                'roleid' => 'PVEAuditor', 'propagate' => $index % 2,
            ];
        }
        $snapshot = (new PveExecutorPermissionSnapshotParser())->parse($this->configuration(), $matrix, $acl);
        self::assertCount(PveExecutorPermissionSnapshot::MAXIMUM_MATRIX_PATHS, $snapshot->backupPermissionMatrix);
        self::assertCount(PveExecutorPermissionSnapshot::MAXIMUM_ACL_ENTRIES, $snapshot->scanAcl);
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidConfigurationProvider')]
    public function testConfigurationRejectsInvalidBindingsIdentitiesPurposesAndProducts(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type (intentionally malformed runtime-boundary input) */
        new PveExecutorEvidenceEndpointConfiguration(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidConfigurationProvider(): iterable
    {
        $self = new self('placeholder');
        $valid = $self->configurationArguments();
        foreach ([0, 1] as $index) {
            $case = $valid; $case[$index] = 'bad'; yield 'id '.$index => [$case];
        }
        foreach ([2, 3, 4] as $index) {
            $case = $valid; $case[$index] = 0; yield 'revision '.$index => [$case];
        }
        $case = $valid; $case[5] = 6; yield 'major' => [$case];
        $case = $valid; $case[9] = 'bad'; yield 'backup identity' => [$case];
        $case = $valid; $case[12] = 'bad'; yield 'scan identity' => [$case];
        $case = $valid; $case[12] = $case[9]; yield 'same identity' => [$case];
        $case = $valid; $case[11] = SecretContext::forBinaryCredentialId('backup-cred-0001', SecretPurpose::PveCollectorToken); yield 'backup purpose' => [$case];
        $case = $valid; $case[14] = SecretContext::forBinaryCredentialId('scan-cred-000001', SecretPurpose::PveBackupToken); yield 'scan purpose' => [$case];
        $case = $valid; $case[6] = 'bad host'; yield 'host' => [$case];
        $case = $valid; $case[7] = 0; yield 'port' => [$case];
    }

    private function configuration(): PveExecutorEvidenceEndpointConfiguration
    {
        return new PveExecutorEvidenceEndpointConfiguration(...$this->configurationArguments());
    }

    /**
     * @return array{
     *   string, string, int, int, int, int, string, int, PveTlsConfiguration,
     *   string, EncryptedSecret, SecretContext, string, EncryptedSecret, SecretContext
     * }
     */
    private function configurationArguments(): array
    {
        return [
            self::CONNECTION, self::ENDPOINT, 4, 5, 6, 9, 'pve.test', 8006,
            PveTlsConfiguration::systemCa(), 'backup@pve!hoddmimir', EncryptedSecret::fromEncoded('backup-envelope'),
            SecretContext::forBinaryCredentialId('backup-cred-0001', SecretPurpose::PveBackupToken),
            'scan@pve!inventory', EncryptedSecret::fromEncoded('scan-envelope'),
            SecretContext::forBinaryCredentialId('scan-cred-000001', SecretPurpose::PveCollectorToken),
        ];
    }
}
