<?php

declare(strict_types=1);

namespace App\Application\Backup\Queue;

use App\Domain\Shared\UInt64Decimal;

final readonly class ExpectedBackupSize
{
    public function calculate(?string $lastSuccessSize, ?string $provisionedSize): ?UInt64Decimal
    {
        if (null === $lastSuccessSize) {
            return null === $provisionedSize ? null : new UInt64Decimal($provisionedSize);
        }
        $value = new UInt64Decimal($lastSuccessSize);
        $tenth = self::divideCeiling($value->value, 10);
        $sum = self::add($value->value, $tenth);

        return new UInt64Decimal($sum);
    }

    private static function divideCeiling(string $value, int $divisor): string
    {
        $result = '';
        $remainder = 0;
        foreach (\str_split($value) as $digit) {
            $number = $remainder * 10 + (int) $digit;
            $result .= (string) \intdiv($number, $divisor);
            $remainder = $number % $divisor;
        }
        $result = \ltrim($result, '0') ?: '0';

        return 0 === $remainder ? $result : self::add($result, '1');
    }

    private static function add(string $left, string $right): string
    {
        $length = \max(\strlen($left), \strlen($right));
        $left = \str_pad($left, $length, '0', STR_PAD_LEFT);
        $right = \str_pad($right, $length, '0', STR_PAD_LEFT);
        $carry = 0;
        $result = '';
        for ($index = $length - 1; $index >= 0; --$index) {
            $number = (int) $left[$index] + (int) $right[$index] + $carry;
            $result = (string) ($number % 10).$result;
            $carry = \intdiv($number, 10);
        }

        return (0 === $carry ? '' : (string) $carry).$result;
    }
}
