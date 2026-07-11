<?php

declare(strict_types=1);

namespace App\Application\Security;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class EncryptedSecret
{
    private const MAXIMUM_ENVELOPE_LENGTH_BYTES = 8192;
    private const PRINTABLE_ASCII = '!"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    private function __construct(private SensitiveParameterValue $encoded)
    {
    }

    public static function fromEncoded(#[SensitiveParameter] string $encoded): self
    {
        $length = strlen($encoded);

        if ($length === 0 || $length > self::MAXIMUM_ENVELOPE_LENGTH_BYTES) {
            throw new InvalidArgumentException('The encrypted secret has an invalid length.');
        }

        if (strspn($encoded, self::PRINTABLE_ASCII) !== $length) {
            throw new InvalidArgumentException('The encrypted secret contains unsupported characters.');
        }

        return new self(new SensitiveParameterValue($encoded));
    }

    public function encoded(): string
    {
        $encoded = $this->encoded->getValue();
        if (!is_string($encoded)) {
            throw new LogicException('Encrypted secret storage is invalid.');
        }

        return $encoded;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Encrypted secrets cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Encrypted secrets cannot be unserialized.');
    }
}
