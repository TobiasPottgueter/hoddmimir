<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class DockerSecretKeyRingLoader implements EncryptionKeyRingProvider
{
    private const MAXIMUM_READ_LENGTH_BYTES = 65537;

    private ?EncryptionKeyRing $loaded = null;
    private ?EncryptionConfigurationFailure $failure = null;

    public function __construct(
        private readonly string $filePath,
        private readonly string $expectedRevision,
    ) {
    }

    public function load(): EncryptionKeyRing
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        if ($this->failure !== null) {
            throw EncryptionConfigurationException::for($this->failure);
        }

        try {
            if (!str_starts_with($this->filePath, '/')) {
                throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::SecretFileUnavailable);
            }

            $expectedRevision = $this->parseExpectedRevision();
            $contents = @file_get_contents($this->filePath, false, null, 0, self::MAXIMUM_READ_LENGTH_BYTES);
            if ($contents === false) {
                throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::SecretFileUnavailable);
            }

            return $this->loaded = EncryptionKeyRing::fromJson($contents, $expectedRevision);
        } catch (EncryptionConfigurationException $exception) {
            $this->failure = $exception->failure;

            throw EncryptionConfigurationException::for($this->failure);
        }
    }

    private function parseExpectedRevision(): int
    {
        $length = strlen($this->expectedRevision);
        if (
            $length < 1
            || $length > 19
            || $this->expectedRevision[0] < '1'
            || $this->expectedRevision[0] > '9'
            || strspn($this->expectedRevision, '0123456789') !== $length
        ) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidRevision);
        }

        $revision = (int) $this->expectedRevision;
        if ($revision < 1 || (string) $revision !== $this->expectedRevision) {
            throw EncryptionConfigurationException::for(EncryptionConfigurationFailure::InvalidRevision);
        }

        return $revision;
    }
}
