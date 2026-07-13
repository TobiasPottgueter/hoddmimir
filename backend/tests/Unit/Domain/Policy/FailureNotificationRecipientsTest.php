<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Policy;

use App\Domain\Policy\FailureNotificationRecipients;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FailureNotificationRecipientsTest extends TestCase
{
    public function testEmptyAndCanonicalSortedListsAreSupported(): void
    {
        self::assertSame([], (new FailureNotificationRecipients([]))->addresses);
        self::assertSame(
            ['alerts@example.test', 'Platform@example.test'],
            (new FailureNotificationRecipients(['Platform@example.test', 'alerts@example.test']))->addresses,
        );
    }

    /** @param list<string> $addresses */
    #[DataProvider('invalidLists')]
    public function testInvalidListsAreRejected(array $addresses): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FailureNotificationRecipients($addresses);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function invalidLists(): iterable
    {
        yield 'padded' => [[' alerts@example.test']];
        yield 'blank' => [['']];
        yield 'malformed' => [['not-an-email']];
        yield 'oversized' => [[str_repeat('x', 243).'@example.test']];
        yield 'duplicate case insensitive' => [['alerts@example.test', 'ALERTS@example.test']];
        yield 'too many' => [array_fill(0, 33, 'alerts@example.test')];
    }
}
