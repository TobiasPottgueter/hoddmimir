<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http\Auth;

use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\OpaqueToken;
use App\Presentation\Http\Auth\OpaqueTokenCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpaqueTokenCodecTest extends TestCase
{
    public function testRoundTripsExactlyThirtyTwoBytesWithoutPadding(): void
    {
        $codec = new OpaqueTokenCodec();
        $token = new OpaqueToken(random_bytes(32));
        $encoded = $codec->encode($token);
        self::assertSame(43, strlen($encoded));
        self::assertStringNotContainsString('=', $encoded);
        self::assertSame($token->bytes(), $codec->decode($encoded)->bytes());
    }

    #[DataProvider('invalid')]
    public function testRejectsNonCanonicalCookieAndCsrfValues(string $value): void
    {
        $this->expectException(AuthenticationFailed::class);
        (new OpaqueTokenCodec())->decode($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'short' => ['short'];
        yield 'padding' => [str_repeat('A', 42).'='];
        yield 'alphabet' => [str_repeat('A', 42).'+'];
    }
}
