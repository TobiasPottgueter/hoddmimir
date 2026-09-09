<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Inventory\Connection;

use App\Application\Inventory\Connection\ConnectionId;
use App\Application\Inventory\Connection\ConnectionScanCatalog;
use App\Application\Inventory\Connection\ConnectionScanTarget;
use App\Application\Inventory\Connection\EndpointId;
use App\Application\Inventory\Connection\EndpointReadFailure;
use App\Application\Inventory\Connection\EndpointReadFailureCode;
use App\Application\Inventory\Connection\EndpointScanReference;
use App\Application\Inventory\Connection\ProxmoxProduct;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionCatalogModelsTest extends TestCase
{
    public function testIdsAreOpaqueFixedWidthValues(): void
    {
        $connectionId = new ConnectionId(str_repeat("\x01", 16));
        $endpointId = new EndpointId(str_repeat("\x02", 16));

        self::assertSame(str_repeat('01', 16), $connectionId->toHex());
        self::assertSame(str_repeat('02', 16), $endpointId->toHex());
        self::assertSame("\x01", $connectionId->bytes[0]);
        self::assertSame("\x02", $endpointId->bytes[0]);
    }

    #[DataProvider('invalidIdProvider')]
    public function testIdsRejectInvalidWidths(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ConnectionId($bytes);
    }

    #[DataProvider('invalidIdProvider')]
    public function testEndpointIdsRejectInvalidWidths(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EndpointId($bytes);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 15)];
        yield 'long' => [str_repeat('a', 17)];
    }

    public function testTargetSortsEndpointsByPriorityThenOpaqueIdAndExposesNoConnectionDetails(): void
    {
        $highId = new EndpointId(str_repeat("\x20", 16));
        $lowId = new EndpointId(str_repeat("\x10", 16));
        $later = new EndpointId(str_repeat("\x01", 16));
        $target = new ConnectionScanTarget(
            new ConnectionId(str_repeat("\x30", 16)),
            7,
            ProxmoxProduct::Pve,
            [
                new EndpointScanReference($later, 200),
                new EndpointScanReference($highId, 100),
                new EndpointScanReference($lowId, 100),
            ],
        );

        self::assertSame([$lowId, $highId, $later], array_map(
            static fn (EndpointScanReference $endpoint): EndpointId => $endpoint->endpointId,
            $target->endpoints,
        ));
        $targetFields = array_keys(get_object_vars($target));
        sort($targetFields, SORT_STRING);
        self::assertSame(['connectionId', 'endpoints', 'expectedRevision', 'product'], $targetFields);
        self::assertSame(['endpointId', 'priority'], array_keys(get_object_vars($target->endpoints[0])));
        self::assertSame(7, $target->expectedRevision);
        self::assertSame(ProxmoxProduct::Pve, $target->product);
    }

    public function testCatalogPortReturnsOnlyEnabledTargets(): void
    {
        $target = new ConnectionScanTarget(
            new ConnectionId(str_repeat("\x01", 16)),
            1,
            ProxmoxProduct::Pbs,
            [],
        );
        $catalog = new InMemoryConnectionScanCatalog([$target]);

        self::assertSame([$target], $catalog->enabledTargets());
    }

    #[DataProvider('invalidPriorityProvider')]
    public function testEndpointPriorityIsUnsigned16Bit(int $priority): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EndpointScanReference(new EndpointId(str_repeat('e', 16)), $priority);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPriorityProvider(): iterable
    {
        yield 'negative' => [-1];
        yield 'too large' => [65_536];
    }

    public function testPriorityAcceptsBothBoundaryValues(): void
    {
        self::assertSame(0, (new EndpointScanReference(new EndpointId(str_repeat('a', 16)), 0))->priority);
        self::assertSame(65_535, (new EndpointScanReference(new EndpointId(str_repeat('b', 16)), 65_535))->priority);
    }

    public function testTargetRejectsInvalidRevisionDuplicateEndpointsAndInvalidRuntimeList(): void
    {
        $id = new EndpointId(str_repeat('e', 16));

        try {
            new ConnectionScanTarget(new ConnectionId(str_repeat('c', 16)), 0, ProxmoxProduct::Pve, []);
            self::fail('A zero revision was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('A connection revision must be positive.', $failure->getMessage());
        }

        try {
            new ConnectionScanTarget(
                new ConnectionId(str_repeat('c', 16)),
                1,
                ProxmoxProduct::Pve,
                [new EndpointScanReference($id, 1), new EndpointScanReference($id, 2)],
            );
            self::fail('A duplicate endpoint was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('An endpoint may occur only once in a scan target.', $failure->getMessage());
        }

        try {
            /** @phpstan-ignore argument.type (exercise the runtime boundary) */
            new ConnectionScanTarget(new ConnectionId(str_repeat('c', 16)), 1, ProxmoxProduct::Pve, ['not-an-endpoint']);
            self::fail('An invalid endpoint list was accepted.');
        } catch (InvalidArgumentException $failure) {
            self::assertSame('The endpoint scan references are invalid.', $failure->getMessage());
        }
    }

    #[DataProvider('endpointFailureProvider')]
    public function testEndpointFailureTaxonomyIsExhaustiveAndSecretFree(
        EndpointReadFailureCode $code,
        bool $allowsFailover,
    ): void {
        $failure = EndpointReadFailure::for($code);

        self::assertSame($code, $failure->failureCode);
        self::assertSame($allowsFailover, $code->allowsEndpointFailover());
        self::assertSame('The Proxmox endpoint read failed.', $failure->getMessage());
    }

    /** @return iterable<string, array{EndpointReadFailureCode, bool}> */
    public static function endpointFailureProvider(): iterable
    {
        yield 'transport' => [EndpointReadFailureCode::Transport, true];
        yield 'TLS' => [EndpointReadFailureCode::Tls, true];
        yield 'unsupported' => [EndpointReadFailureCode::UnsupportedProductOrVersion, true];
        yield 'root unusable' => [EndpointReadFailureCode::RootUnusable, true];
        yield 'wrong installation identity' => [EndpointReadFailureCode::WrongIdentity, true];
        yield 'decrypt or missing credential' => [EndpointReadFailureCode::CredentialUnavailable, false];
        yield 'authentication' => [EndpointReadFailureCode::Authentication, false];
        yield '403 permission denied' => [EndpointReadFailureCode::PermissionDenied, false];
    }
}

/** @internal */
final readonly class InMemoryConnectionScanCatalog implements ConnectionScanCatalog
{
    /** @param list<ConnectionScanTarget> $targets */
    public function __construct(private array $targets)
    {
    }

    public function enabledTargets(): array
    {
        return $this->targets;
    }
}
