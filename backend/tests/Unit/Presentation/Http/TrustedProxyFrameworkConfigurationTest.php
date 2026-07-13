<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyFrameworkConfigurationTest extends TestCase
{
    private const string ENVIRONMENT_NAME = 'HODDMIMIR_TRUSTED_PROXY';
    private const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    protected function setUp(): void
    {
        $this->clearTrustedProxyEnvironment();
        Request::setTrustedProxies([], 0);
    }

    protected function tearDown(): void
    {
        $this->clearTrustedProxyEnvironment();
        Request::setTrustedProxies([], 0);
    }

    public function testDefaultConfigurationTrustsNoProxyOrForwardedHeader(): void
    {
        $this->bootKernel();

        self::assertSame([], Request::getTrustedProxies());
        self::assertSame(0, Request::getTrustedHeaderSet());
    }

    public function testConfigurationTrustsOnlyTheExactIpAndRequiredHeaders(): void
    {
        $this->setTrustedProxyEnvironment('192.0.2.1');
        $this->bootKernel();

        self::assertSame(['192.0.2.1'], Request::getTrustedProxies());
        self::assertSame(self::TRUSTED_HEADERS, Request::getTrustedHeaderSet());
        self::assertSame(0, Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_HOST);
        self::assertSame(0, Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PORT);
        self::assertSame(0, Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PREFIX);
    }

    private function bootKernel(): void
    {
        $kernel = new Kernel('test', false);

        try {
            $kernel->boot();
        } finally {
            $kernel->shutdown();
        }
    }

    private function setTrustedProxyEnvironment(string $value): void
    {
        putenv(self::ENVIRONMENT_NAME.'='.$value);
        $_ENV[self::ENVIRONMENT_NAME] = $value;
        $_SERVER[self::ENVIRONMENT_NAME] = $value;
    }

    private function clearTrustedProxyEnvironment(): void
    {
        putenv(self::ENVIRONMENT_NAME);
        unset($_ENV[self::ENVIRONMENT_NAME], $_SERVER[self::ENVIRONMENT_NAME]);
    }
}
