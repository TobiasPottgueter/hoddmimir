<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveReadFailureCode: string
{
    case CredentialUnavailable = 'credential_unavailable';
    case Transport = 'transport';
    case Authentication = 'authentication';
    case PermissionDenied = 'permission_denied';
    case RateLimited = 'rate_limited';
    case RemoteUnavailable = 'remote_unavailable';
    case NotFound = 'not_found';
    case HttpStatus = 'http_status';
    case InvalidEnvelope = 'invalid_envelope';
    case InvalidResponse = 'invalid_response';
    case UnsupportedVersion = 'unsupported_version';
}
