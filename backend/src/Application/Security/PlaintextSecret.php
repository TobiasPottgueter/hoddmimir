<?php

declare(strict_types=1);

namespace App\Application\Security;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

final readonly class PlaintextSecret
{
    private const MAXIMUM_LENGTH_BYTES = 4096;
    private const UNSUPPORTED_CONTROL_CHARACTERS = "\x00\x01\x02\x03\x04\x05\x06\x07"
        ."\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F"
        ."\x10\x11\x12\x13\x14\x15\x16\x17"
        ."\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    private function __construct(private SensitiveParameterValue $value)
    {
    }

    public static function fromString(#[SensitiveParameter] string $value): self
    {
        $length = strlen($value);

        if ($length === 0 || $length > self::MAXIMUM_LENGTH_BYTES) {
            throw new InvalidArgumentException('The submitted secret has an invalid length.');
        }

        if (strcspn($value, self::UNSUPPORTED_CONTROL_CHARACTERS) !== $length) {
            throw new InvalidArgumentException('The submitted secret contains unsupported control characters.');
        }

        return new self(new SensitiveParameterValue($value));
    }

    /**
     * Limits explicit plaintext access to the supplied operation.
     *
     * @template TResult
     *
     * @param callable(string): TResult $consumer
     *
     * @return TResult
     */
    public function consume(callable $consumer): mixed
    {
        $value = $this->value->getValue();
        if (!is_string($value)) {
            throw new LogicException('Plaintext secret storage is invalid.');
        }

        return $consumer($value);
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
        throw new LogicException('Plaintext secrets cannot be serialized.');
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Plaintext secrets cannot be unserialized.');
    }
}
