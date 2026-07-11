<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Security\EncryptionKeyRing;
use App\Infrastructure\Security\NonceSource;
use App\Infrastructure\Security\SecretCipherException;
use App\Infrastructure\Security\SecretCipherFailure;
use App\Infrastructure\Security\SodiumSecretCipher;
use App\Infrastructure\Security\SystemNonceSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SodiumSecretCipherTest extends TestCase
{
    private const KEY_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const KEY_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testItEncryptsDeterministicallyWithAFixedNonceAndDecryptsTheEnvelope(): void
    {
        $nonce = str_repeat("\x01", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);
        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], [$nonce, $nonce]);
        $plaintext = PlaintextSecret::fromString('token-sentinel');

        $first = $cipher->encrypt($plaintext, $context);
        $second = $cipher->encrypt($plaintext, $context);

        self::assertSame($first->encoded(), $second->encoded());
        self::assertStringStartsWith('hoddmimir-secret:1:key_a:', $first->encoded());
        self::assertStringNotContainsString('token-sentinel', $first->encoded());
        self::assertSame('key_a', $cipher->primaryKeyId());
        self::assertSame(
            'token-sentinel',
            $cipher->decrypt($first, $context)->consume(static fn (string $value): string => $value),
        );
    }

    public function testFreshNoncesProduceDifferentEnvelopes(): void
    {
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);
        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], [
            str_repeat("\x01", 24),
            str_repeat("\x02", 24),
        ]);
        $plaintext = PlaintextSecret::fromString('same-secret');

        self::assertNotSame(
            $cipher->encrypt($plaintext, $context)->encoded(),
            $cipher->encrypt($plaintext, $context)->encoded(),
        );
    }

    public function testCredentialAndPurposeBindingRejectsEnvelopeReuse(): void
    {
        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], [str_repeat("\x03", 24)]);
        $source = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);
        $encrypted = $cipher->encrypt(PlaintextSecret::fromString('bound-secret'), $source);

        foreach ([
            SecretContext::forCredential('credential-2', SecretPurpose::PveCollectorToken),
            SecretContext::forCredential('credential-1', SecretPurpose::PbsCollectorToken),
            SecretContext::forCredential('credential-1', SecretPurpose::PveBackupToken),
        ] as $wrongContext) {
            $this->assertCipherFailure(
                static fn () => $cipher->decrypt($encrypted, $wrongContext),
                SecretCipherFailure::DecryptionFailed,
            );
        }
    }

    public function testNewKeyringDecryptsOldEnvelopesAndUsesTheNewPrimaryForWrites(): void
    {
        $context = SecretContext::forCredential('credential-rotation', SecretPurpose::PveCollectorToken);
        $oldCipher = $this->cipher(
            'key_old',
            [['id' => 'key_old', 'material' => self::KEY_A]],
            [str_repeat("\x04", 24)],
        );
        $oldEnvelope = $oldCipher->encrypt(PlaintextSecret::fromString('rotating-secret'), $context);

        $rotatedCipher = $this->cipher('key_new', [
            ['id' => 'key_old', 'material' => self::KEY_A],
            ['id' => 'key_new', 'material' => self::KEY_B],
        ], [str_repeat("\x05", 24)]);

        self::assertSame(
            'rotating-secret',
            $rotatedCipher->decrypt($oldEnvelope, $context)->consume(static fn (string $value): string => $value),
        );

        $rewrapped = $rotatedCipher->encrypt($rotatedCipher->decrypt($oldEnvelope, $context), $context);
        self::assertStringStartsWith('hoddmimir-secret:1:key_new:', $rewrapped->encoded());
        self::assertSame(
            'rotating-secret',
            $rotatedCipher->decrypt($rewrapped, $context)->consume(static fn (string $value): string => $value),
        );
    }

    public function testItClassifiesMalformedAndUnsupportedEnvelopes(): void
    {
        $nonce = self::encode(str_repeat("\x01", 24));
        $minimumCiphertext = self::encode(str_repeat("\x02", 16));
        $cases = [
            ['not-an-envelope', SecretCipherFailure::MalformedEnvelope],
            ["other:1:key_a:{$nonce}:{$minimumCiphertext}", SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:2:key_a:{$nonce}:{$minimumCiphertext}", SecretCipherFailure::UnsupportedVersion],
            ["hoddmimir-secret:1:INVALID:{$nonce}:{$minimumCiphertext}", SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:key_a:*:{$minimumCiphertext}", SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:key_a:A:{$minimumCiphertext}", SecretCipherFailure::MalformedEnvelope],
            ['hoddmimir-secret:1:key_a:'.self::encode(str_repeat('n', 23)).":{$minimumCiphertext}", SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:key_a:{$nonce}:*", SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:key_a:{$nonce}:".self::encode(str_repeat('c', 15)), SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:key_a:{$nonce}:".self::encode(str_repeat('c', 4113)), SecretCipherFailure::MalformedEnvelope],
            ["hoddmimir-secret:1:missing:{$nonce}:{$minimumCiphertext}", SecretCipherFailure::UnknownKey],
        ];

        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], []);
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);

        foreach ($cases as [$encoded, $failure]) {
            $this->assertCipherFailure(
                static fn () => $cipher->decrypt(EncryptedSecret::fromEncoded($encoded), $context),
                $failure,
            );
        }
    }

    public function testAuthenticatedTamperingIsRejected(): void
    {
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);
        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], [str_repeat("\x06", 24)]);
        $encrypted = $cipher->encrypt(PlaintextSecret::fromString('tamper-secret'), $context);
        $parts = explode(':', $encrypted->encoded());

        $tamperedNonce = self::decode($parts[3]);
        $tamperedNonce[0] = $tamperedNonce[0] ^ "\x01";
        $tamperedCiphertext = self::decode($parts[4]);
        $tamperedCiphertext[0] = $tamperedCiphertext[0] ^ "\x01";

        foreach ([
            implode(':', [$parts[0], $parts[1], $parts[2], self::encode($tamperedNonce), $parts[4]]),
            implode(':', [$parts[0], $parts[1], $parts[2], $parts[3], self::encode($tamperedCiphertext)]),
        ] as $tampered) {
            $this->assertCipherFailure(
                static fn () => $cipher->decrypt(EncryptedSecret::fromEncoded($tampered), $context),
                SecretCipherFailure::DecryptionFailed,
            );
        }
    }

    public function testAuthenticatedButInvalidPlaintextIsRejected(): void
    {
        $context = SecretContext::forCredential('credential-invalid-plaintext', SecretPurpose::PveCollectorToken);
        $nonce = str_repeat("\x07", 24);
        $derivedKey = sodium_crypto_kdf_derive_from_key(32, 1, 'HODDSEC1', sodium_hex2bin(self::KEY_A));
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            '',
            $context->additionalAuthenticatedData(1, 'key_a'),
            $nonce,
            $derivedKey,
        );
        sodium_memzero($derivedKey);

        $envelope = EncryptedSecret::fromEncoded(sprintf(
            'hoddmimir-secret:1:key_a:%s:%s',
            self::encode($nonce),
            self::encode($ciphertext),
        ));
        $cipher = $this->cipher('key_a', [['id' => 'key_a', 'material' => self::KEY_A]], []);

        $this->assertCipherFailure(
            static fn () => $cipher->decrypt($envelope, $context),
            SecretCipherFailure::DecryptionFailed,
        );
    }

    public function testEncryptionFailsSafelyForInvalidOrFailingNonceSources(): void
    {
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PveCollectorToken);
        $plaintext = PlaintextSecret::fromString('nonce-source-secret');

        $invalidNonceCipher = new SodiumSecretCipher(
            $this->keyRing('key_a', [['id' => 'key_a', 'material' => self::KEY_A]]),
            new FixedNonceSource(['too-short']),
        );
        $this->assertCipherFailure(
            static fn () => $invalidNonceCipher->encrypt($plaintext, $context),
            SecretCipherFailure::EncryptionFailed,
        );

        $throwingCipher = new SodiumSecretCipher(
            $this->keyRing('key_a', [['id' => 'key_a', 'material' => self::KEY_A]]),
            new ThrowingNonceSource(),
        );
        $this->assertCipherFailure(
            static fn () => $throwingCipher->encrypt($plaintext, $context),
            SecretCipherFailure::EncryptionFailed,
        );
    }

    public function testSystemNonceSourceProducesTheRequiredLength(): void
    {
        self::assertSame(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES,
            strlen((new SystemNonceSource())->nextNonce()),
        );
    }

    /**
     * @param list<array{id: string, material: string}> $keys
     * @param list<string>                              $nonces
     */
    private function cipher(string $primaryKeyId, array $keys, array $nonces): SodiumSecretCipher
    {
        return new SodiumSecretCipher(
            $this->keyRing($primaryKeyId, $keys),
            new FixedNonceSource($nonces),
        );
    }

    /**
     * @param list<array{id: string, material: string}> $keys
     */
    private function keyRing(string $primaryKeyId, array $keys): EncryptionKeyRing
    {
        return EncryptionKeyRing::fromJson(json_encode([
            'format' => 1,
            'revision' => 1,
            'primaryKeyId' => $primaryKeyId,
            'keys' => $keys,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param callable(): mixed $operation
     */
    private function assertCipherFailure(callable $operation, SecretCipherFailure $failure): void
    {
        try {
            $operation();
            self::fail('Invalid secret operation was accepted.');
        } catch (SecretCipherException $exception) {
            self::assertSame($failure, $exception->failure);
            self::assertContains(
                $exception->getMessage(),
                ['Secret encryption failed.', 'Encrypted secret is unavailable.'],
            );
            self::assertStringNotContainsString('nonce-source-secret', $exception->getMessage());
            self::assertStringNotContainsString('THROWING-NONCE-SENTINEL', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    private static function encode(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private static function decode(string $value): string
    {
        return sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}

final class FixedNonceSource implements NonceSource
{
    /**
     * @param list<string> $nonces
     */
    public function __construct(private array $nonces)
    {
    }

    public function nextNonce(): string
    {
        $nonce = array_shift($this->nonces);
        if ($nonce === null) {
            throw new RuntimeException('No test nonce remains.');
        }

        return $nonce;
    }
}

final readonly class ThrowingNonceSource implements NonceSource
{
    public function nextNonce(): string
    {
        throw new RuntimeException('THROWING-NONCE-SENTINEL');
    }
}
