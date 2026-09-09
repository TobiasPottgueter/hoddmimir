<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use InvalidArgumentException;

final readonly class UInt64Decimal
{
    public const string MAXIMUM = '18446744073709551615';

    public function __construct(public string $value)
    {
        if ('' === $value || !ctype_digit($value)
            || (strlen($value) > 1 && '0' === $value[0])
            || strlen($value) > strlen(self::MAXIMUM)
            || (strlen($value) === strlen(self::MAXIMUM) && strcmp($value, self::MAXIMUM) > 0)) {
            throw new InvalidArgumentException('An unsigned 64-bit decimal must be canonical and in range.');
        }
    }

    public function lessThanOrEqual(self $other): bool
    {
        $length = strlen($this->value) <=> strlen($other->value);
        return $length < 0 || (0 === $length && strcmp($this->value, $other->value) <= 0);
    }

    public function minus(self $other): self
    {
        if (!$other->lessThanOrEqual($this)) {
            throw new InvalidArgumentException('An unsigned decimal subtraction cannot be negative.');
        }
        $right = str_pad($other->value, strlen($this->value), '0', STR_PAD_LEFT);
        $result = '';
        $borrow = 0;
        for ($index = strlen($this->value) - 1; $index >= 0; --$index) {
            $digit = (int) $this->value[$index] - (int) $right[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit + 10 * $borrow).$result;
        }
        $result = ltrim($result, '0');
        return new self('' === $result ? '0' : $result);
    }
}
