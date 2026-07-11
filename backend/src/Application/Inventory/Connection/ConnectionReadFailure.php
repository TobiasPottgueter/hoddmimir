<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use RuntimeException;

final class ConnectionReadFailure extends RuntimeException
{
    private const MESSAGES = [
        'no_endpoints' => 'The Proxmox connection has no enabled endpoint.',
        'invalid_binding' => 'The installation binding does not belong to the connection product.',
        'connection_changed' => 'The Proxmox connection changed while its inventory was being read.',
        'snapshot_invalid' => 'The Proxmox inventory snapshot could not be mapped safely.',
        'terminal_endpoint_failure' => 'The Proxmox connection read failed without endpoint failover.',
        'endpoints_exhausted' => 'No eligible Proxmox endpoint produced a usable installation snapshot.',
    ];

    private function __construct(
        public readonly ConnectionReadFailureCode $failureCode,
        public readonly ?EndpointId $endpointId = null,
        public readonly ?EndpointReadFailureCode $endpointFailureCode = null,
    ) {
        parent::__construct(self::MESSAGES[$failureCode->value]);
    }

    public static function noEndpoints(): self
    {
        return new self(ConnectionReadFailureCode::NoEndpoints);
    }

    public static function invalidBinding(): self
    {
        return new self(ConnectionReadFailureCode::InvalidBinding);
    }

    public static function connectionChanged(): self
    {
        return new self(ConnectionReadFailureCode::ConnectionChanged);
    }

    public static function terminal(EndpointId $endpointId, EndpointReadFailureCode $endpointFailureCode): self
    {
        return new self(ConnectionReadFailureCode::TerminalEndpointFailure, $endpointId, $endpointFailureCode);
    }

    public static function exhausted(EndpointId $endpointId, EndpointReadFailureCode $endpointFailureCode): self
    {
        return new self(ConnectionReadFailureCode::EndpointsExhausted, $endpointId, $endpointFailureCode);
    }
}
