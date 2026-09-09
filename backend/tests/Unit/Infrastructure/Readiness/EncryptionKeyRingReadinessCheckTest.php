<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Readiness;

use App\Application\Security\ReferencedCredentialKeyIds;
use App\Infrastructure\Readiness\EncryptionKeyRingReadinessCheck;
use App\Infrastructure\Security\DockerSecretKeyRingLoader;
use App\Infrastructure\Security\EncryptionKeyRing;
use App\Infrastructure\Security\EncryptionKeyRingProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class EncryptionKeyRingReadinessCheckTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $temporaryDirectory = sys_get_temp_dir().'/hoddmimir-keyring-readiness-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($temporaryDirectory, 0o700));
        $this->temporaryDirectory = $temporaryDirectory;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testItIsReadyWithNoCredentialUsage(): void
    {
        $check = $this->check($this->writeKeyRing(), []);

        self::assertSame('encryption_keyring', $check->name());
        self::assertSame(['status' => 'ready'], $check->check()->toArray());
    }

    public function testItIsReadyWhenEveryReferencedKeyExists(): void
    {
        $check = $this->check(
            $this->writeKeyRing(keys: [
                ['id' => 'key_old', 'material' => str_repeat('a', 64)],
                ['id' => 'key_current', 'material' => str_repeat('b', 64)],
            ], primaryKeyId: 'key_current'),
            ['key_current', 'key_old'],
        );

        self::assertSame(['status' => 'ready'], $check->check()->toArray());
    }

    public function testItMapsAMissingSecretFileWithoutExposingThePath(): void
    {
        $path = $this->temporaryDirectory.'/missing-SENTINEL-keyring.json';
        $result = $this->check($path, [])->check()->toArray();

        self::assertSame(['status' => 'unavailable', 'reason' => 'missing'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testItMapsInvalidKeyringDataWithoutExposingIt(): void
    {
        $path = $this->temporaryDirectory.'/invalid.json';
        file_put_contents($path, '{"material":"SENTINEL"');

        $result = $this->check($path, [])->check()->toArray();

        self::assertSame(['status' => 'unavailable', 'reason' => 'invalid'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testItMapsAnUnexpectedLoaderFailureWithoutExposingIt(): void
    {
        $check = new EncryptionKeyRingReadinessCheck(
            new ThrowingEncryptionKeyRingProvider(),
            new StubReferencedCredentialKeyIds([]),
        );

        $result = $check->check()->toArray();

        self::assertSame(['status' => 'unavailable', 'reason' => 'invalid'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testItMapsARevisionMismatch(): void
    {
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'revision_mismatch'],
            $this->check($this->writeKeyRing(revision: 2), [], expectedRevision: '1')->check()->toArray(),
        );
    }

    public function testItMapsAnInvalidExpectedRevisionWithoutThrowingDuringConstruction(): void
    {
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'revision_mismatch'],
            $this->check($this->writeKeyRing(), [], expectedRevision: 'not-an-integer')->check()->toArray(),
        );
    }

    public function testItMapsARelativeSecretPathWithoutThrowingDuringConstruction(): void
    {
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'missing'],
            $this->check('relative-SENTINEL/keyring', [])->check()->toArray(),
        );
    }

    public function testItFailsClosedWhenCredentialKeyUsageCannotBeRead(): void
    {
        $result = $this->check(
            $this->writeKeyRing(),
            [],
            failure: new RuntimeException('DATABASE-SENTINEL'),
        )->check()->toArray();

        self::assertSame(['status' => 'unavailable', 'reason' => 'usage_unavailable'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testItFailsClosedWithoutExposingAMissingReferencedKeyId(): void
    {
        $result = $this->check(
            $this->writeKeyRing(),
            ['missing_key_SENTINEL'],
        )->check()->toArray();

        self::assertSame(['status' => 'unavailable', 'reason' => 'missing_referenced_key'], $result);
        self::assertStringNotContainsString('SENTINEL', json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $referencedKeyIds
     */
    private function check(
        string $path,
        array $referencedKeyIds,
        string $expectedRevision = '1',
        ?Throwable $failure = null,
    ): EncryptionKeyRingReadinessCheck {
        return new EncryptionKeyRingReadinessCheck(
            new DockerSecretKeyRingLoader($path, $expectedRevision),
            new StubReferencedCredentialKeyIds($referencedKeyIds, $failure),
        );
    }

    /**
     * @param list<array{id: string, material: string}>|null $keys
     */
    private function writeKeyRing(
        int $revision = 1,
        ?array $keys = null,
        string $primaryKeyId = 'key_current',
    ): string {
        $path = $this->temporaryDirectory.'/keyring-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, json_encode([
            'format' => 1,
            'revision' => $revision,
            'primaryKeyId' => $primaryKeyId,
            'keys' => $keys ?? [
                ['id' => 'key_current', 'material' => str_repeat('a', 64)],
            ],
        ], JSON_THROW_ON_ERROR));

        return $path;
    }
}

final readonly class StubReferencedCredentialKeyIds implements ReferencedCredentialKeyIds
{
    /** @param list<string> $keyIds */
    public function __construct(
        private array $keyIds,
        private ?Throwable $failure = null,
    ) {
    }

    public function referencedKeyIds(): array
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->keyIds;
    }
}

final readonly class ThrowingEncryptionKeyRingProvider implements EncryptionKeyRingProvider
{
    public function load(): EncryptionKeyRing
    {
        throw new RuntimeException('LOADER-SENTINEL');
    }
}
