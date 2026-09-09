<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Readiness;

use App\Application\Readiness\ReadinessCheckResult;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadinessCheckResultTest extends TestCase
{
    /** @return iterable<string, array{callable(): ReadinessCheckResult}> */
    public static function invalidResults(): iterable
    {
        yield 'empty name' => [static fn () => ReadinessCheckResult::ready('')];
        yield 'name too long' => [static fn () => ReadinessCheckResult::ready(str_repeat('a', 65))];
        yield 'invalid name' => [static fn () => ReadinessCheckResult::ready('INVALID-NAME')];
        yield 'name starts above lowercase range' => [static fn () => ReadinessCheckResult::ready('{invalid')];
        yield 'invalid character after first' => [static fn () => ReadinessCheckResult::ready('invalid-name')];
        yield 'invalid reason' => [static fn () => ReadinessCheckResult::unavailable('check', 'unsafe reason')];
    }

    #[DataProvider('invalidResults')]
    public function testItRejectsUnsafeIdentifiers(callable $factory): void
    {
        $this->expectException(InvalidArgumentException::class);

        $factory();
    }

    public function testSafeIdentifiersAllowLowercaseDigitsAndUnderscores(): void
    {
        self::assertSame(
            ['status' => 'unavailable', 'reason' => 'reason_2'],
            ReadinessCheckResult::unavailable('check_1', 'reason_2')->toArray(),
        );
    }
}
