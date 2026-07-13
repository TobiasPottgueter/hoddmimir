<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class TrustedProxyContractTest extends TestCase
{
    private const string TRUSTED_PROXY = '192.0.2.1';
    private const int TRUSTED_HEADERS = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO;

    protected function setUp(): void
    {
        Request::setTrustedProxies([self::TRUSTED_PROXY], self::TRUSTED_HEADERS);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], 0);
    }

    public function testDirectRequestCannotSpoofItsClientIpOrScheme(): void
    {
        $request = $this->request('198.51.100.10', '203.0.113.11');

        self::assertSame('198.51.100.10', $request->getClientIp());
        self::assertFalse($request->isSecure());
    }

    public function testExactlyTrustedImmediateProxyProvidesClientIpAndScheme(): void
    {
        $request = $this->request(self::TRUSTED_PROXY, '203.0.113.11');

        self::assertSame('203.0.113.11', $request->getClientIp());
        self::assertTrue($request->isSecure());
    }

    public function testNeighbourContainerCannotActAsTheTrustedProxy(): void
    {
        $request = $this->request('192.0.2.2', '203.0.113.11');

        self::assertSame('192.0.2.2', $request->getClientIp());
        self::assertFalse($request->isSecure());
    }

    public function testForwardedHostAndPortAreNeverTrusted(): void
    {
        $request = $this->request(self::TRUSTED_PROXY, '203.0.113.11');

        self::assertSame('internal.example:8080', $request->getHttpHost());
        self::assertSame(8080, $request->getPort());
        self::assertSame('https://internal.example:8080', $request->getSchemeAndHttpHost());
    }

    private function request(string $remoteAddress, string $forwardedFor): Request
    {
        return Request::create('http://internal.example:8080/api/v1/auth/login', server: [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'attacker.example',
            'HTTP_X_FORWARDED_PORT' => '444',
        ]);
    }
}
