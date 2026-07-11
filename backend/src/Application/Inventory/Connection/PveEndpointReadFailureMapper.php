<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

use App\Application\Proxmox\Pve\PveReadFailure;

final readonly class PveEndpointReadFailureMapper
{
    /** @var array<string, EndpointReadFailureCode> */
    private const FAILURE_CODES = [
        'credential_unavailable' => EndpointReadFailureCode::CredentialUnavailable,
        'authentication' => EndpointReadFailureCode::Authentication,
        'permission_denied' => EndpointReadFailureCode::PermissionDenied,
        'unsupported_version' => EndpointReadFailureCode::UnsupportedProductOrVersion,
        'transport' => EndpointReadFailureCode::Transport,
        'rate_limited' => EndpointReadFailureCode::Transport,
        'remote_unavailable' => EndpointReadFailureCode::Transport,
        'not_found' => EndpointReadFailureCode::RootUnusable,
        'http_status' => EndpointReadFailureCode::RootUnusable,
        'invalid_envelope' => EndpointReadFailureCode::RootUnusable,
        'invalid_response' => EndpointReadFailureCode::RootUnusable,
    ];

    public function map(PveReadFailure $failure): EndpointReadFailure
    {
        return EndpointReadFailure::for(self::FAILURE_CODES[$failure->failureCode->value]);
    }
}
