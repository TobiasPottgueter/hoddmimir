<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\PveEndpointReadFailureMapper;
use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveEndpointReadFailureMapperTest extends TestCase
{
    #[DataProvider('failureProvider')]
    public function testItMapsOnlyTheTypedStableFailureCode(
        PveReadFailureCode $source,
        EndpointReadFailureCode $expected,
    ): void {
        $mapped = (new PveEndpointReadFailureMapper())->map(PveReadFailure::for($source));

        self::assertSame($expected, $mapped->failureCode);
        self::assertSame('The Proxmox endpoint read failed.', $mapped->getMessage());
    }

    /** @return iterable<string, array{PveReadFailureCode, EndpointReadFailureCode}> */
    public static function failureProvider(): iterable
    {
        yield 'credential unavailable' => [PveReadFailureCode::CredentialUnavailable, EndpointReadFailureCode::CredentialUnavailable];
        yield 'authentication' => [PveReadFailureCode::Authentication, EndpointReadFailureCode::Authentication];
        yield 'permission denied' => [PveReadFailureCode::PermissionDenied, EndpointReadFailureCode::PermissionDenied];
        yield 'unsupported version' => [PveReadFailureCode::UnsupportedVersion, EndpointReadFailureCode::UnsupportedProductOrVersion];
        yield 'transport' => [PveReadFailureCode::Transport, EndpointReadFailureCode::Transport];
        yield 'rate limited' => [PveReadFailureCode::RateLimited, EndpointReadFailureCode::Transport];
        yield 'remote unavailable' => [PveReadFailureCode::RemoteUnavailable, EndpointReadFailureCode::Transport];
        yield 'not found' => [PveReadFailureCode::NotFound, EndpointReadFailureCode::RootUnusable];
        yield 'unexpected status' => [PveReadFailureCode::HttpStatus, EndpointReadFailureCode::RootUnusable];
        yield 'invalid envelope' => [PveReadFailureCode::InvalidEnvelope, EndpointReadFailureCode::RootUnusable];
        yield 'invalid response' => [PveReadFailureCode::InvalidResponse, EndpointReadFailureCode::RootUnusable];
    }
}
