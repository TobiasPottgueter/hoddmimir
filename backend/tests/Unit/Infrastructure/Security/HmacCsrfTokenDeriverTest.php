<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Application\Security\Auth\OpaqueToken;
use App\Infrastructure\Security\HmacCsrfTokenDeriver;
use App\Infrastructure\Security\Sha256OpaqueSecretHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HmacCsrfTokenDeriverTest extends TestCase
{
    public function testDerivationIsStableKeyedAndDomainSeparatedFromSessionDigest(): void
    {
        $session = new OpaqueToken(str_repeat('s', 32));
        $first = new HmacCsrfTokenDeriver(str_repeat('a', 32));
        $second = new HmacCsrfTokenDeriver(str_repeat('b', 32));
        $csrf = $first->derive($session);

        self::assertSame($csrf->bytes(), $first->derive($session)->bytes());
        self::assertNotSame($csrf->bytes(), $second->derive($session)->bytes());
        self::assertNotSame($csrf->bytes(), $session->bytes());
        self::assertNotSame(
            (new Sha256OpaqueSecretHasher())->digestToken($session)->binary(),
            (new Sha256OpaqueSecretHasher())->digestToken($csrf)->binary(),
        );
        self::assertSame(['value' => '[REDACTED]'], $session->__debugInfo());
    }

    public function testRejectsShortApplicationSecretAndWrongTokenLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HmacCsrfTokenDeriver('short');
    }

    public function testOpaqueTokenRejectsWrongLength(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OpaqueToken('short');
    }
}
