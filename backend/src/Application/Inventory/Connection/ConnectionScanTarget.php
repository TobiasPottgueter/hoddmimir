<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use InvalidArgumentException;

final readonly class ConnectionScanTarget
{
    /** @var list<EndpointScanReference> */
    public array $endpoints;

    /**
     * This DTO is intentionally secret-free. Endpoint addresses, TLS material,
     * principals, credential references and encrypted values stay behind the
     * Infrastructure reader port.
     *
     * @param list<EndpointScanReference> $endpoints
     */
    public function __construct(
        public ConnectionId $connectionId,
        public int $expectedRevision,
        public ProxmoxProduct $product,
        array $endpoints,
    ) {
        if ($expectedRevision < 1) {
            throw new InvalidArgumentException('A connection revision must be positive.');
        }

        $seen = [];
        foreach ($endpoints as $endpoint) {
            // @phpstan-ignore instanceof.alwaysTrue (enforce the runtime boundary promised by the PHPDoc)
            if (!$endpoint instanceof EndpointScanReference) {
                throw new InvalidArgumentException('The endpoint scan references are invalid.');
            }
            if (isset($seen[$endpoint->endpointId->toHex()])) {
                throw new InvalidArgumentException('An endpoint may occur only once in a scan target.');
            }
            $seen[$endpoint->endpointId->toHex()] = true;
        }

        usort($endpoints, static function (EndpointScanReference $left, EndpointScanReference $right): int {
            return $left->priority <=> $right->priority
                ?: strcmp($left->endpointId->bytes, $right->endpointId->bytes);
        });
        $this->endpoints = $endpoints;
    }
}
