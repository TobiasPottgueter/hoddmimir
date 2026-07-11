<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use JsonException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final class EncryptionKeyRing
{
    private const FORMAT_VERSION = 1;
    private const MAXIMUM_JSON_LENGTH_BYTES = 65536;
    private const MAXIMUM_KEY_COUNT = 16;
    private const KEY_ID_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,31}\z/';
    private const KEY_MATERIAL_PATTERN = '/\A[0-9a-f]{64}\z/';

    /**
     * @param non-empty-array<string, SensitiveParameterValue> $keys Binary key material indexed by key ID.
     */
    private function __construct(
        private readonly int $revision,
        private readonly string $primaryKeyId,
        private readonly array $keys,
    ) {
    }

    public static function fromJson(#[SensitiveParameter] string $json, ?int $expectedRevision = null): self
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_JSON_LENGTH_BYTES) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidJson);
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidJson);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidStructure);
        }

        self::requireExactKeys($decoded, ['format', 'revision', 'primaryKeyId', 'keys']);

        if ($decoded['format'] !== self::FORMAT_VERSION) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidStructure);
        }

        $revision = $decoded['revision'];
        if (!is_int($revision) || $revision < 1 || ($expectedRevision !== null && $revision !== $expectedRevision)) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidRevision);
        }

        $primaryKeyId = $decoded['primaryKeyId'];
        if (!is_string($primaryKeyId) || !self::isValidKeyId($primaryKeyId)) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidPrimaryKey);
        }

        $entries = $decoded['keys'];
        if (!is_array($entries) || !array_is_list($entries) || $entries === [] || count($entries) > self::MAXIMUM_KEY_COUNT) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidStructure);
        }

        /** @var array<string, SensitiveParameterValue> $keys */
        $keys = [];
        /** @var array<string, true> $materials */
        $materials = [];

        foreach ($entries as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidKey);
            }

            self::requireExactKeys($entry, ['id', 'material'], EncryptionConfigurationFailure::InvalidKey);

            $keyId = $entry['id'];
            $material = $entry['material'];
            if (
                !is_string($keyId)
                || !self::isValidKeyId($keyId)
                || !is_string($material)
                || preg_match(self::KEY_MATERIAL_PATTERN, $material) !== 1
            ) {
                throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidKey);
            }

            if (isset($keys[$keyId]) || isset($materials[$material])) {
                throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::DuplicateKey);
            }

            $keys[$keyId] = new SensitiveParameterValue(sodium_hex2bin($material));
            $materials[$material] = true;
        }

        if (!isset($keys[$primaryKeyId])) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidPrimaryKey);
        }

        /** @var non-empty-array<string, SensitiveParameterValue> $keys */
        return new self($revision, $primaryKeyId, $keys);
    }

    public function revision(): int
    {
        return $this->revision;
    }

    public function primaryKeyId(): string
    {
        return $this->primaryKeyId;
    }

    public function primaryKey(): string
    {
        return $this->unwrapKey($this->keys[$this->primaryKeyId]);
    }

    public function key(string $keyId): ?string
    {
        $key = $this->keys[$keyId] ?? null;

        return $key === null ? null : $this->unwrapKey($key);
    }

    public function hasKey(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public static function isValidKeyId(string $keyId): bool
    {
        return preg_match(self::KEY_ID_PATTERN, $keyId) === 1;
    }

    /**
     * @return array{revision: int, primaryKeyId: string, keyIds: list<string>}
     */
    public function __debugInfo(): array
    {
        return [
            'revision' => $this->revision,
            'primaryKeyId' => $this->primaryKeyId,
            'keyIds' => array_keys($this->keys),
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Encryption key rings cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Encryption key rings cannot be unserialized.');
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $requiredKeys
     */
    private static function requireExactKeys(
        array $value,
        array $requiredKeys,
        EncryptionConfigurationFailure $failure = EncryptionConfigurationFailure::InvalidStructure,
    ): void {
        $actualKeys = array_keys($value);
        sort($actualKeys);
        sort($requiredKeys);

        if ($actualKeys !== $requiredKeys) {
            throw EncryptionConfigurationException::for($failure);
        }
    }

    private function unwrapKey(SensitiveParameterValue $key): string
    {
        $material = $key->getValue();
        if (!is_string($material)) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidKey);
        }

        return $material;
    }
}
