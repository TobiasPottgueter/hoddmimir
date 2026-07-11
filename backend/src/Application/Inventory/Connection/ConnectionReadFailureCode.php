<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum ConnectionReadFailureCode: string
{
    case NoEndpoints = 'no_endpoints';
    case InvalidBinding = 'invalid_binding';
    case ConnectionChanged = 'connection_changed';
    case SnapshotInvalid = 'snapshot_invalid';
    case TerminalEndpointFailure = 'terminal_endpoint_failure';
    case EndpointsExhausted = 'endpoints_exhausted';
}
