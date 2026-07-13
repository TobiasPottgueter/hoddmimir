<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

final readonly class OnboardingAsciiValidator
{
    private const string LETTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const string LOWERCASE = 'abcdefghijklmnopqrstuvwxyz';
    private const string DIGITS = '0123456789';
    private const string TOKEN_CHARACTERS = self::LETTERS.self::DIGITS.'._-';

    public static function hasNormalizedBoundary(string $value): bool
    {
        if (!isset($value[0])) {
            return false;
        }
        $last = $value[strlen($value) - 1];
        return !self::isOneOf($value[0], " \t\n\r\0\x0B") && !self::isOneOf($last, " \t\n\r\0\x0B");
    }

    public static function isIdempotencyKey(string $value): bool
    {
        $length = strlen($value);
        if ($length < 1 || $length > 128) {
            return false;
        }
        if (!self::isOneOf($value[0], self::LETTERS.self::DIGITS)) {
            return false;
        }
        return self::allCharactersIn($value, self::TOKEN_CHARACTERS.':');
    }

    public static function isTokenId(string $value): bool
    {
        $at = -1;
        $bang = -1;
        for ($index = 0; isset($value[$index]); ++$index) {
            $character = $value[$index];
            if ('@' === $character) {
                if ($at >= 0 || $bang >= 0) {
                    return false;
                }
                $at = $index;
                continue;
            }
            if ('!' === $character) {
                if ($at < 1 || $bang >= 0) {
                    return false;
                }
                $bang = $index;
                continue;
            }
            if (!self::isOneOf($character, self::TOKEN_CHARACTERS)) {
                return false;
            }
        }
        return $at > 0 && $bang > $at + 1 && $bang < strlen($value) - 1;
    }

    public static function isGuidanceId(string $value): bool
    {
        $length = strlen($value);
        if ($length < 1 || $length > 64) {
            return false;
        }
        if (!self::isOneOf($value[0], self::LOWERCASE.self::DIGITS)) {
            return false;
        }
        return self::allCharactersIn($value, self::LOWERCASE.self::DIGITS.'-');
    }

    public static function containsNewline(string $value): bool
    {
        for ($index = 0; isset($value[$index]); ++$index) {
            if ("\n" === $value[$index]) {
                return true;
            }
        }
        return false;
    }

    public static function contains(string $value, string $needle): bool
    {
        $valueLength = strlen($value);
        $needleLength = strlen($needle);
        if (0 === $needleLength || $needleLength > $valueLength) {
            return false;
        }
        for ($start = 0; $start <= $valueLength - $needleLength; ++$start) {
            $matches = true;
            for ($offset = 0; $offset < $needleLength; ++$offset) {
                if ($value[$start + $offset] !== $needle[$offset]) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    public static function isLowerHex(string $value, int $length): bool
    {
        return strlen($value) === $length && self::allCharactersIn($value, self::DIGITS.'abcdef');
    }

    public static function isPermissionPath(string $value): bool
    {
        if (!isset($value[0]) || '/' !== $value[0]) {
            return false;
        }
        if (!isset($value[1])) {
            return true;
        }
        $segmentLength = 0;
        for ($index = 1; isset($value[$index]); ++$index) {
            if ('/' === $value[$index]) {
                if (0 === $segmentLength) {
                    return false;
                }
                $segmentLength = 0;
                continue;
            }
            if (!self::isOneOf($value[$index], self::TOKEN_CHARACTERS)) {
                return false;
            }
            ++$segmentLength;
        }
        return $segmentLength > 0;
    }

    public static function isPrivilege(string $value): bool
    {
        $segmentStart = true;
        $dots = 0;
        for ($index = 0; isset($value[$index]); ++$index) {
            $character = $value[$index];
            if ('.' === $character) {
                if ($segmentStart) {
                    return false;
                }
                $segmentStart = true;
                ++$dots;
                continue;
            }
            if ($segmentStart) {
                if (!self::isOneOf($character, self::LETTERS)) {
                    return false;
                }
                $segmentStart = false;
                continue;
            }
            if (!self::isOneOf($character, self::LETTERS.self::DIGITS)) {
                return false;
            }
        }
        return !$segmentStart && $dots > 0;
    }

    public static function isLowerUuid(string $value): bool
    {
        if (36 !== strlen($value)) {
            return false;
        }
        for ($index = 0; $index < 36; ++$index) {
            if (8 === $index || 13 === $index || 18 === $index || 23 === $index) {
                if ('-' !== $value[$index]) {
                    return false;
                }
                continue;
            }
            if (!self::isOneOf($value[$index], self::DIGITS.'abcdef')) {
                return false;
            }
        }
        return true;
    }

    public static function isUtcTimestamp(string $value): bool
    {
        if (27 !== strlen($value)) {
            return false;
        }
        foreach ([4 => '-', 7 => '-', 10 => 'T', 13 => ':', 16 => ':', 19 => '.', 26 => 'Z'] as $index => $separator) {
            if ($value[$index] !== $separator) {
                return false;
            }
        }
        for ($index = 0; $index < 26; ++$index) {
            if (isset([4 => true, 7 => true, 10 => true, 13 => true, 16 => true, 19 => true][$index])) {
                continue;
            }
            if (!self::isOneOf($value[$index], self::DIGITS)) {
                return false;
            }
        }

        return self::twoDigitsInRange($value, 5, 1, 12)
            && self::twoDigitsInRange($value, 8, 1, 31)
            && self::twoDigitsInRange($value, 11, 0, 23)
            && self::twoDigitsInRange($value, 14, 0, 59)
            && self::twoDigitsInRange($value, 17, 0, 59);
    }

    public static function isHost(string $value): bool
    {
        $length = strlen($value);
        if ($length < 1 || $length > 255 || !self::hasNormalizedBoundary($value)) {
            return false;
        }
        if ('[' === $value[0]) {
            return ']' === $value[$length - 1] && self::isIpv6($value, 1, $length - 1);
        }
        if (self::contains($value, ':')) {
            return self::isIpv6($value, 0, $length);
        }
        if (self::allCharactersIn($value, self::DIGITS.'.')) {
            return self::isIpv4($value, 0, $length);
        }

        return self::isDnsName($value);
    }

    private static function isIpv4(string $value, int $start, int $end): bool
    {
        $octets = 0;
        $digits = 0;
        $number = 0;
        for ($index = $start; $index < $end; ++$index) {
            $character = $value[$index];
            if ('.' === $character) {
                if (0 === $digits || $number > 255) {
                    return false;
                }
                ++$octets;
                $digits = 0;
                $number = 0;
                continue;
            }
            if (!self::isOneOf($character, self::DIGITS)) {
                return false;
            }
            if (1 === $digits && '0' === $value[$index - 1]) {
                return false;
            }
            ++$digits;
            $number = ($number * 10) + (int) $character;
        }

        return 3 === $octets && $digits > 0 && $digits <= 3 && $number <= 255;
    }

    private static function isIpv6(string $value, int $start, int $end): bool
    {
        if ($start >= $end) {
            return false;
        }
        $groups = 0;
        $compressed = false;
        $index = $start;
        if (':' === $value[$index]) {
            if ($index + 1 >= $end || ':' !== $value[$index + 1]) {
                return false;
            }
            $compressed = true;
            $index += 2;
            if ($index === $end) {
                return true;
            }
        }
        while ($index < $end) {
            $groupStart = $index;
            $groupLength = 0;
            while ($index < $end && self::isOneOf($value[$index], self::DIGITS.'abcdefABCDEF')) {
                ++$groupLength;
                ++$index;
                if ($groupLength > 4) {
                    return false;
                }
            }
            if ($index < $end && '.' === $value[$index]) {
                if (!self::isIpv4($value, $groupStart, $end)) {
                    return false;
                }
                $groups += 2;
                $index = $end;
                break;
            }
            if (0 === $groupLength) {
                return false;
            }
            ++$groups;
            if ($index === $end) {
                break;
            }
            if (':' !== $value[$index]) {
                return false;
            }
            if ($index + 1 < $end && ':' === $value[$index + 1]) {
                if ($compressed) {
                    return false;
                }
                $compressed = true;
                $index += 2;
                if ($index === $end) {
                    break;
                }
                continue;
            }
            ++$index;
            if ($index === $end) {
                return false;
            }
        }

        return $compressed ? $groups < 8 : 8 === $groups;
    }

    private static function isDnsName(string $value): bool
    {
        if (strlen($value) > 253) {
            return false;
        }
        $labelLength = 0;
        for ($index = 0; isset($value[$index]); ++$index) {
            $character = $value[$index];
            if ('.' === $character) {
                if (0 === $labelLength || '-' === $value[$index - 1]) {
                    return false;
                }
                $labelLength = 0;
                continue;
            }
            if (!self::isOneOf($character, self::LETTERS.self::DIGITS.'-')) {
                return false;
            }
            if ((0 === $labelLength && '-' === $character) || ++$labelLength > 63) {
                return false;
            }
        }

        return $labelLength > 0 && '-' !== $value[strlen($value) - 1];
    }

    private static function twoDigitsInRange(string $value, int $index, int $minimum, int $maximum): bool
    {
        $number = ((int) $value[$index] * 10) + (int) $value[$index + 1];

        return $number >= $minimum && $number <= $maximum;
    }

    private static function allCharactersIn(string $value, string $allowed): bool
    {
        for ($index = 0; isset($value[$index]); ++$index) {
            if (!self::isOneOf($value[$index], $allowed)) {
                return false;
            }
        }
        return true;
    }

    private static function isOneOf(string $character, string $allowed): bool
    {
        for ($index = 0; isset($allowed[$index]); ++$index) {
            if ($character === $allowed[$index]) {
                return true;
            }
        }
        return false;
    }
}
