<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use InvalidArgumentException;
use Throwable;

final readonly class SodiumSecretCipher implements SecretCipher
{
    private const ENVELOPE_PREFIX = 'hoddmimir-secret';
    private const ENVELOPE_VERSION = 1;
    private const KDF_CONTEXT = 'HODDSEC1';
    private const MAXIMUM_PLAINTEXT_LENGTH_BYTES = 4096;

    public function __construct(
        private EncryptionKeyRing $keyRing,
        private NonceSource $nonceSource,
    ) {
    }

    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret
    {
        $keyId = $this->keyRing->primaryKeyId();
        $nonce = '';
        $derivedKey = null;

        try {
            $nonce = $this->nonceSource->nextNonce();
            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
                throw SecretCipherException::for(SecretCipherFailure::EncryptionFailed);
            }

            $derivedKey = $this->deriveKey($this->keyRing->primaryKey(), $context);
            $additionalData = $context->additionalAuthenticatedData(self::ENVELOPE_VERSION, $keyId);
            $ciphertext = $plaintext->consume(
                static fn (string $value): string => sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                    $value,
                    $additionalData,
                    $nonce,
                    $derivedKey,
                ),
            );

            return EncryptedSecret::fromEncoded(implode(':', [
                self::ENVELOPE_PREFIX,
                (string) self::ENVELOPE_VERSION,
                $keyId,
                self::base64UrlEncode($nonce),
                self::base64UrlEncode($ciphertext),
            ]));
        } catch (Throwable) {
            throw SecretCipherException::for(SecretCipherFailure::EncryptionFailed);
        } finally {
            if (is_string($derivedKey)) {
                sodium_memzero($derivedKey);
            }
        }
    }

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret
    {
        $envelope = $this->parseEnvelope($encrypted);
        $masterKey = $this->keyRing->key($envelope['keyId']);
        if ($masterKey === null) {
            throw SecretCipherException::for(SecretCipherFailure::UnknownKey);
        }

        $derivedKey = null;
        try {
            $derivedKey = $this->deriveKey($masterKey, $context);
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $envelope['ciphertext'],
                $context->additionalAuthenticatedData(self::ENVELOPE_VERSION, $envelope['keyId']),
                $envelope['nonce'],
                $derivedKey,
            );
        } catch (Throwable) {
            throw SecretCipherException::for(SecretCipherFailure::DecryptionFailed);
        } finally {
            if (is_string($derivedKey)) {
                sodium_memzero($derivedKey);
            }
        }

        if ($plaintext === false) {
            throw SecretCipherException::for(SecretCipherFailure::DecryptionFailed);
        }

        try {
            return PlaintextSecret::fromString($plaintext);
        } catch (InvalidArgumentException) {
            throw SecretCipherException::for(SecretCipherFailure::DecryptionFailed);
        }
    }

    public function primaryKeyId(): string
    {
        return $this->keyRing->primaryKeyId();
    }

    /**
     * @return array{keyId: string, nonce: string, ciphertext: string}
     */
    private function parseEnvelope(EncryptedSecret $encrypted): array
    {
        $parts = explode(':', $encrypted->encoded());
        if (count($parts) !== 5 || $parts[0] !== self::ENVELOPE_PREFIX) {
            throw SecretCipherException::for(SecretCipherFailure::MalformedEnvelope);
        }

        if ($parts[1] !== (string) self::ENVELOPE_VERSION) {
            throw SecretCipherException::for(SecretCipherFailure::UnsupportedVersion);
        }

        $keyId = $parts[2];
        if (!EncryptionKeyRing::isValidKeyId($keyId)) {
            throw SecretCipherException::for(SecretCipherFailure::MalformedEnvelope);
        }

        $nonce = self::base64UrlDecode($parts[3]);
        $ciphertext = self::base64UrlDecode($parts[4]);
        if (
            $nonce === null
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || $ciphertext === null
            || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            || strlen($ciphertext) > self::MAXIMUM_PLAINTEXT_LENGTH_BYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
        ) {
            throw SecretCipherException::for(SecretCipherFailure::MalformedEnvelope);
        }

        return [
            'keyId' => $keyId,
            'nonce' => $nonce,
            'ciphertext' => $ciphertext,
        ];
    }

    private function deriveKey(string $masterKey, SecretContext $context): string
    {
        return sodium_crypto_kdf_derive_from_key(
            SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
            $context->purpose()->kdfSubkeyId(),
            self::KDF_CONTEXT,
            $masterKey,
        );
    }

    private static function base64UrlEncode(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return null;
        }

        try {
            $decoded = sodium_base642bin($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (Throwable) {
            return null;
        }

        return self::base64UrlEncode($decoded) === $value ? $decoded : null;
    }
}
