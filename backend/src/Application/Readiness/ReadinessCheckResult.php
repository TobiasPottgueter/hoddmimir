<?php

declare(strict_types=1);

namespace App\Application\Readiness;

use InvalidArgumentException;

final readonly class ReadinessCheckResult
{
    private function __construct(
        public string $name,
        public bool $ready,
        public ?string $reason,
    ) {
        if (!self::isSafeIdentifier($this->name)) {
            throw new InvalidArgumentException('A readiness check name must be a stable lowercase identifier.');
        }

        if (null !== $this->reason && !self::isSafeIdentifier($this->reason)) {
            throw new InvalidArgumentException('A readiness result reason must be a stable lowercase identifier.');
        }
    }

    public static function ready(string $name): self
    {
        return new self($name, true, null);
    }

    public static function unavailable(string $name, string $reason): self
    {
        return new self($name, false, $reason);
    }

    /** @return array{status: 'ready'}|array{status: 'unavailable', reason: string} */
    public function toArray(): array
    {
        if ($this->ready) {
            return ['status' => 'ready'];
        }

        return ['status' => 'unavailable', 'reason' => (string) $this->reason];
    }

    private static function isSafeIdentifier(string $value): bool
    {
        $length = strlen($value);
        if ($length < 1 || $length > 64) {
            return false;
        }

        if ($value[0] < 'a' || $value[0] > 'z') {
            return false;
        }

        return strspn($value, 'abcdefghijklmnopqrstuvwxyz0123456789_', 1) === $length - 1;
    }
}
