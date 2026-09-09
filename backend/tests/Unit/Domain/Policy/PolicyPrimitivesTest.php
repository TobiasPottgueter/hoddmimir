<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\PolicyId;
use App\Domain\Policy\PolicyRevision;
use App\Domain\Policy\PolicyPriority;
use App\Domain\Policy\PolicyStatus;
use App\Domain\Policy\SelectionValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PolicyPrimitivesTest extends TestCase
{
    public function testPolicyIdPreservesBinaryIdentity(): void
    {
        $id = new PolicyId(str_repeat("\x01", 16));

        self::assertSame(str_repeat("\x01", 16), $id->binary());
        self::assertTrue($id->equals(new PolicyId(str_repeat("\x01", 16))));
        self::assertFalse($id->equals(new PolicyId(str_repeat("\x02", 16))));
    }

    #[DataProvider('invalidIdProvider')]
    public function testPolicyIdRejectsInvalidLengths(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyId($bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdProvider(): iterable
    {
        yield 'short' => [str_repeat('a', 15)];
        yield 'long' => [str_repeat('a', 17)];
    }

    public function testRevisionIsPositiveAndMonotone(): void
    {
        self::assertSame(2, (new PolicyRevision(1))->next()->value);
    }

    public function testRevisionRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyRevision(0);
    }

    public function testMaximumRevisionCannotAdvance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PolicyRevision(PHP_INT_MAX))->next();
    }

    public function testStatusesAndSelectionValuesStayClosed(): void
    {
        self::assertTrue(PolicyStatus::Enabled->executable());
        self::assertFalse(PolicyStatus::Draft->executable());
        self::assertFalse(PolicyStatus::Disabled->executable());
        self::assertFalse(SelectionValue::Inherit->explicit());
        self::assertTrue(SelectionValue::Include->explicit());
        self::assertTrue(SelectionValue::Exclude->explicit());
    }

    #[DataProvider('policyPriorityProvider')]
    public function testPolicyPriorityKeepsTheExplicitTieBreakerBoundary(int $value): void
    {
        self::assertSame($value, (new PolicyPriority($value))->value);
    }

    /** @return iterable<string, array{int}> */
    public static function policyPriorityProvider(): iterable
    {
        yield 'minimum' => [0];
        yield 'maximum' => [1_000];
    }

    #[DataProvider('invalidPolicyPriorityProvider')]
    public function testPolicyPriorityRejectsValuesOutsideItsOwnTieBreakerRange(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PolicyPriority($value);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPolicyPriorityProvider(): iterable
    {
        yield 'below minimum' => [-1];
        yield 'above maximum' => [1_001];
    }
}
