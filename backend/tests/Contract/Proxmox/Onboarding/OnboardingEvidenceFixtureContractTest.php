<?php

declare(strict_types=1);

namespace App\Tests\Contract\Proxmox\Onboarding;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingEvidenceVerifier;
use App\Application\Configuration\Connection\Onboarding\OnboardingIdentityEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingPermission;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingRemoteEvidence;
use App\Application\Configuration\Connection\Onboarding\OnboardingRoleDefinition;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OnboardingEvidenceFixtureContractTest extends TestCase
{
    #[DataProvider('supportedVersions')]
    public function testSanitizedHttpEvidenceSupportsEveryPromisedVersion(
        OnboardingProduct $product,
        int $major,
        string $fixture,
    ): void {
        $raw = file_get_contents($fixture);
        self::assertIsString($raw);
        foreach (['tokenSecret', 'password', 'Authorization', 'PVEAPIToken', 'PBSAPIToken'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $raw);
        }
        $document = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        /** @var array<string, mixed> $document */

        $evidence = OnboardingProduct::Pve === $product
            ? $this->pveEvidence($document)
            : $this->pbsEvidence($document);
        $verification = (new OnboardingEvidenceVerifier())->verify($this->command($product), $evidence);

        self::assertTrue($verification->passed());
        self::assertSame($major, $evidence->identities[0]->major);
        self::assertSame([], $verification->issues);
    }

    /** @return iterable<string, array{OnboardingProduct, int, string}> */
    public static function supportedVersions(): iterable
    {
        $fixtures = dirname(__DIR__, 3).'/Fixtures/Proxmox';
        yield 'PVE 7' => [OnboardingProduct::Pve, 7, $fixtures.'/Pve/7/onboarding-evidence.json'];
        yield 'PVE 8' => [OnboardingProduct::Pve, 8, $fixtures.'/Pve/8/onboarding-evidence.json'];
        yield 'PVE 9' => [OnboardingProduct::Pve, 9, $fixtures.'/Pve/9/onboarding-evidence.json'];
        yield 'PBS 3' => [OnboardingProduct::Pbs, 3, $fixtures.'/Pbs/3/onboarding-evidence.json'];
        yield 'PBS 4' => [OnboardingProduct::Pbs, 4, $fixtures.'/Pbs/4/onboarding-evidence.json'];
    }

    /** @param array<string, mixed> $document */
    private function pveEvidence(array $document): OnboardingRemoteEvidence
    {
        self::assertSame([
            'version', 'roles', 'acl', 'scanPermissions', 'backupPermissions',
            'scanPropagationProbes', 'backupPropagationProbes',
        ], array_keys($document));
        [$major, $minor, $rawVersion] = $this->version($document['version'] ?? null);
        $rolesEnvelope = $document['roles'] ?? null;
        self::assertIsArray($rolesEnvelope);
        self::assertSame(['data'], array_keys($rolesEnvelope));
        $rows = $rolesEnvelope['data'];
        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows));
        $roles = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertSame(['roleid', 'privs'], array_keys($row));
            self::assertIsString($row['roleid']);
            self::assertIsString($row['privs']);
            $roles[] = new OnboardingRoleDefinition($row['roleid'], '' === $row['privs'] ? [] : explode(',', $row['privs']));
        }
        $this->assertPveAcl($document['acl'] ?? null);
        $scanPropagation = $this->pvePropagation($document['scanPropagationProbes'] ?? null, [
            '/access' => ['/', 'Sys.Audit'],
            '/nodes/hoddmimir-propagation-probe' => ['/nodes', 'Sys.Audit'],
            '/vms/999999999' => ['/vms', 'VM.Audit'],
            '/pool/hoddmimir-propagation-probe' => ['/pool', 'Pool.Audit'],
            '/storage/hoddmimir-propagation-probe' => ['/storage', 'Datastore.Audit'],
        ]);
        $backupPropagation = $this->pvePropagation($document['backupPropagationProbes'] ?? null, [
            '/vms/999999999' => ['/vms', 'VM.Backup'],
            '/storage/hoddmimir-propagation-probe' => ['/storage', 'Datastore.AllocateSpace'],
        ]);

        return new OnboardingRemoteEvidence(true, [
            new OnboardingIdentityEvidence(
                OnboardingCredentialKind::Scan,
                OnboardingProduct::Pve,
                $major,
                $minor,
                $rawVersion,
                $this->pvePermissions($document['scanPermissions'] ?? null, $scanPropagation),
            ),
            new OnboardingIdentityEvidence(
                OnboardingCredentialKind::Backup,
                OnboardingProduct::Pve,
                $major,
                $minor,
                $rawVersion,
                $this->pvePermissions($document['backupPermissions'] ?? null, $backupPropagation),
            ),
        ], $roles);
    }

    /** @param array<string, mixed> $document */
    private function pbsEvidence(array $document): OnboardingRemoteEvidence
    {
        self::assertSame(['version', 'permissionMatrix', 'permissions'], array_keys($document));
        [$major, $minor, $rawVersion] = $this->version($document['version'] ?? null);
        $permissionEnvelopes = $document['permissions'] ?? null;
        self::assertIsArray($permissionEnvelopes);
        self::assertSame(['/system/status', '/system/tasks', '/datastore', '/remote'], array_keys($permissionEnvelopes));
        $permissions = $this->pbsPermissionMatrix($document['permissionMatrix'] ?? null);
        foreach ($permissionEnvelopes as $path => $envelope) {
            self::assertIsArray($envelope);
            self::assertSame(['data'], array_keys($envelope));
            $data = $envelope['data'];
            self::assertIsArray($data);
            self::assertSame([$path], array_keys($data));
            $privileges = $data[$path];
            self::assertIsArray($privileges);
            foreach ($privileges as $privilege => $propagated) {
                self::assertIsBool($propagated);
                $permissions[] = new OnboardingPermission($path, (string) $privilege, true, $propagated);
            }
        }

        return new OnboardingRemoteEvidence(true, [new OnboardingIdentityEvidence(
            OnboardingCredentialKind::Scan,
            OnboardingProduct::Pbs,
            $major,
            $minor,
            $rawVersion,
            $permissions,
        )]);
    }

    /** @return list<OnboardingPermission> */
    private function pbsPermissionMatrix(mixed $envelope): array
    {
        self::assertIsArray($envelope);
        self::assertSame(['data'], array_keys($envelope));
        $matrix = $envelope['data'];
        self::assertIsArray($matrix);
        self::assertFalse(array_is_list($matrix));
        $permissions = [];
        foreach ($matrix as $path => $privileges) {
            self::assertIsArray($privileges);
            foreach ($privileges as $privilege => $propagated) {
                self::assertIsBool($propagated);
                $permissions[] = new OnboardingPermission((string) $path, (string) $privilege, true, $propagated);
            }
        }
        return $permissions;
    }

    /**
     * @param array<string, array<string, bool>> $propagation
     * @return list<OnboardingPermission>
     */
    private function pvePermissions(mixed $envelope, array $propagation): array
    {
        self::assertIsArray($envelope);
        self::assertSame(['data'], array_keys($envelope));
        $matrix = $envelope['data'];
        self::assertIsArray($matrix);
        self::assertFalse(array_is_list($matrix));
        $permissions = [];
        foreach ($matrix as $path => $privileges) {
            self::assertIsArray($privileges);
            foreach ($privileges as $privilege => $reportedPropagation) {
                self::assertTrue(in_array($reportedPropagation, [0, 1], true));
                $probed = $propagation[(string) $path][(string) $privilege] ?? null;
                $permissions[] = new OnboardingPermission(
                    (string) $path,
                    (string) $privilege,
                    true,
                    null === $probed ? 1 === $reportedPropagation : (1 === $reportedPropagation && $probed),
                );
            }
        }
        return $permissions;
    }

    private function assertPveAcl(mixed $envelope): void
    {
        self::assertIsArray($envelope);
        self::assertSame(['data'], array_keys($envelope));
        $rows = $envelope['data'];
        self::assertIsArray($rows);
        self::assertTrue(array_is_list($rows));
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertSame(['path', 'type', 'ugid', 'roleid', 'propagate'], array_keys($row));
            self::assertIsString($row['path']);
            self::assertContains($row['type'], ['user', 'group', 'token']);
            self::assertIsString($row['ugid']);
            self::assertIsString($row['roleid']);
            self::assertContains($row['propagate'], [0, 1]);
        }
    }

    /**
     * @param array<string, array{string, string}> $expected
     * @return array<string, array<string, bool>>
     */
    private function pvePropagation(mixed $probes, array $expected): array
    {
        self::assertIsArray($probes);
        self::assertSame(array_keys($expected), array_keys($probes));
        $result = [];
        foreach ($expected as $child => [$root, $privilege]) {
            $envelope = $probes[$child];
            self::assertIsArray($envelope);
            self::assertSame(['data'], array_keys($envelope));
            $matrix = $envelope['data'];
            self::assertIsArray($matrix);
            self::assertSame([$child], array_keys($matrix));
            $privileges = $matrix[$child];
            self::assertIsArray($privileges);
            self::assertSame([$privilege], array_keys($privileges));
            self::assertContains($privileges[$privilege], [0, 1]);
            $result[$root][$privilege] = 1 === $privileges[$privilege];
        }

        return $result;
    }

    /** @return array{int, int, string} */
    private function version(mixed $envelope): array
    {
        self::assertIsArray($envelope);
        self::assertSame(['data'], array_keys($envelope));
        $data = $envelope['data'];
        self::assertIsArray($data);
        $version = $data['version'] ?? null;
        self::assertIsString($version);
        if (1 !== preg_match('/\A([0-9]+)\.([0-9]+)/D', $version, $parts)) {
            self::fail('The fixture version does not expose a major and minor release.');
        }
        return [(int) $parts[1], (int) $parts[2], $version];
    }

    private function command(OnboardingProduct $product): OnboardingActivationCommand
    {
        $credentials = [new OnboardingCredential(
            OnboardingCredentialKind::Scan,
            OnboardingProduct::Pve === $product ? 'hoddmimir@pve!scan' : 'hoddmimir@pbs!scan',
            OnboardingProduct::Pve === $product ? 'sanitized-fixture-scan-secret' : '00000000-0000-0000-0000-000000000000',
        )];
        if (OnboardingProduct::Pve === $product) {
            $credentials[] = new OnboardingCredential(OnboardingCredentialKind::Backup, 'hoddmimir@pve!backup', 'sanitized-fixture-backup-secret');
        }
        return new OnboardingActivationCommand(
            OnboardingMode::Activate,
            str_repeat('c', 16),
            0,
            'fixture-contract',
            str_repeat('r', 16),
            $product,
            'Fixture Contract',
            new OnboardingEndpoint('proxmox.fixture.test', $product->defaultPort(), OnboardingTlsMode::SystemCa, null, null),
            $credentials,
        );
    }
}
