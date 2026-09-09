<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\SelectionResolver;
use App\Domain\Policy\SelectionScope;
use App\Domain\Policy\SelectionValue;
use PHPUnit\Framework\TestCase;

final class SelectionResolverTest extends TestCase
{
    public function testAllInheritedDefaultsToFailClosedExclusion(): void
    {
        $selection = (new SelectionResolver())->resolve(
            SelectionValue::Inherit,
            SelectionValue::Inherit,
            SelectionValue::Inherit,
            SelectionValue::Inherit,
            SelectionValue::Inherit,
        );

        self::assertFalse($selection->included);
        self::assertNull($selection->decidedBy);
        self::assertSame(SelectionValue::Inherit, $selection->value);
    }

    public function testNearestIncludeExplainsAnIncludedGuest(): void
    {
        $selection = (new SelectionResolver())->resolve(
            SelectionValue::Include,
            SelectionValue::Inherit,
            SelectionValue::Include,
            SelectionValue::Include,
            SelectionValue::Inherit,
        );

        self::assertTrue($selection->included);
        self::assertSame(SelectionScope::Node, $selection->decidedBy);
        self::assertSame(SelectionValue::Include, $selection->value);
    }

    public function testAnyApplicableExplicitExclusionWinsAgainstEveryInclude(): void
    {
        $selection = (new SelectionResolver())->resolve(
            SelectionValue::Include,
            SelectionValue::Exclude,
            SelectionValue::Include,
            SelectionValue::Include,
            SelectionValue::Include,
        );

        self::assertFalse($selection->included);
        self::assertSame(SelectionScope::Connection, $selection->decidedBy);
        self::assertSame(SelectionValue::Exclude, $selection->value);
    }

    public function testMostSpecificExclusionIsUsedForExplainability(): void
    {
        $selection = (new SelectionResolver())->resolve(
            SelectionValue::Exclude,
            SelectionValue::Inherit,
            SelectionValue::Exclude,
            SelectionValue::Inherit,
            SelectionValue::Exclude,
        );

        self::assertFalse($selection->included);
        self::assertSame(SelectionScope::Guest, $selection->decidedBy);
    }
}
