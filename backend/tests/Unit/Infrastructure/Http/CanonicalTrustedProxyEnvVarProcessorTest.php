<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\CanonicalTrustedProxyEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class CanonicalTrustedProxyEnvVarProcessorTest extends TestCase
{
    private CanonicalTrustedProxyEnvVarProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new CanonicalTrustedProxyEnvVarProcessor();
    }

    #[DataProvider('validValues')]
    public function testItAcceptsOnlyTheDisabledValueOrACanonicalSingleIp(string $value): void
    {
        self::assertSame($value, $this->process($value));
    }

    /** @return iterable<string, array{string}> */
    public static function validValues(): iterable
    {
        yield 'disabled' => [''];
        yield 'IPv4' => ['192.0.2.1'];
        yield 'IPv6' => ['2001:db8::1'];
        yield 'IPv4-mapped IPv6' => ['::ffff:192.0.2.1'];
    }

    #[DataProvider('invalidValues')]
    public function testItRejectsBroadDynamicMultipleOrNonCanonicalTrustAnchors(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be empty or contain exactly one canonical IP address');

        $this->process($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidValues(): iterable
    {
        yield 'dynamic remote address' => ['REMOTE_ADDR'];
        yield 'Symfony private ranges' => ['private_ranges'];
        yield 'Symfony private subnets' => ['PRIVATE_SUBNETS'];
        yield 'all IPv4' => ['0.0.0.0/0'];
        yield 'unspecified IPv4' => ['0.0.0.0'];
        yield 'unspecified IPv6' => ['::'];
        yield 'CIDR' => ['192.0.2.0/24'];
        yield 'list' => ['192.0.2.1,192.0.2.2'];
        yield 'whitespace' => [' 192.0.2.1'];
        yield 'non-canonical IPv4' => ['192.000.2.1'];
        yield 'expanded IPv6' => ['2001:0db8::1'];
        yield 'uppercase IPv6' => ['2001:DB8::1'];
    }

    public function testItRejectsNonStringValues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must resolve to a string');

        $this->processor->getEnv(
            'hoddmimir_trusted_proxy',
            'HODDMIMIR_TRUSTED_PROXY',
            static fn (string $name): int => 1,
        );
    }

    public function testItPublishesItsProcessorType(): void
    {
        self::assertSame(
            ['hoddmimir_trusted_proxy' => 'string'],
            CanonicalTrustedProxyEnvVarProcessor::getProvidedTypes(),
        );
    }

    private function process(string $value): string
    {
        return $this->processor->getEnv(
            'hoddmimir_trusted_proxy',
            'HODDMIMIR_TRUSTED_PROXY',
            static fn (string $name): string => $value,
        );
    }
}
