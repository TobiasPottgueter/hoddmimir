<?php

declare(strict_types=1);

namespace App\Application\Security;

use InvalidArgumentException;

final readonly class SecretContext
{
    private const CREDENTIAL_ID_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:-';
    private const CREDENTIAL_ID_INITIAL_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const KEY_ID_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789_-';
    private const KEY_ID_INITIAL_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private function __construct(
        private string $credentialId,
        private SecretPurpose $purpose,
    ) {
    }

    public static function forCredential(string $credentialId, SecretPurpose $purpose): self
    {
        $length = strlen($credentialId);
        if (
            $length === 0
            || $length > 128
            || strspn($credentialId, self::CREDENTIAL_ID_CHARACTERS) !== $length
            || strspn($credentialId[0], self::CREDENTIAL_ID_INITIAL_CHARACTERS) !== 1
        ) {
            throw new InvalidArgumentException('The credential identifier is invalid.');
        }

        return new self($credentialId, $purpose);
    }

    /**
     * MariaDB identifiers are stored as BINARY(16). Their canonical secret
     * context representation is always 32 lowercase hexadecimal characters.
     */
    public static function forBinaryCredentialId(string $credentialId, SecretPurpose $purpose): self
    {
        if (16 !== strlen($credentialId)) {
            throw new InvalidArgumentException('The binary credential identifier is invalid.');
        }

        return self::forCredential(bin2hex($credentialId), $purpose);
    }

    public function purpose(): SecretPurpose
    {
        return $this->purpose;
    }

    public function additionalAuthenticatedData(int $formatVersion, string $keyId): string
    {
        if ($formatVersion < 1) {
            throw new InvalidArgumentException('The secret format version is invalid.');
        }

        $keyIdLength = strlen($keyId);
        if (
            $keyIdLength === 0
            || $keyIdLength > 32
            || strspn($keyId, self::KEY_ID_CHARACTERS) !== $keyIdLength
            || strspn($keyId[0], self::KEY_ID_INITIAL_CHARACTERS) !== 1
        ) {
            throw new InvalidArgumentException('The encryption key identifier is invalid.');
        }

        return 'hoddmimir-secret'
            ."\0v".$formatVersion
            ."\0".$keyId
            ."\0".$this->purpose->value
            ."\0".$this->credentialId
            ."\0token-secret";
    }
}
