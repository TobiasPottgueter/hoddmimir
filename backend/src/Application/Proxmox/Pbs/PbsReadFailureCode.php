<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsReadFailureCode: string
{
    case Transport = 'transport';
    case Authentication = 'authentication';
    case PermissionDenied = 'permission_denied';
    case NotFound = 'not_found';
    case RateLimited = 'rate_limited';
    case RemoteUnavailable = 'remote_unavailable';
    case HttpStatus = 'http_status';
    case InvalidEnvelope = 'invalid_envelope';
    case InvalidResponse = 'invalid_response';
    case UnsupportedVersion = 'unsupported_version';
    case CredentialUnavailable = 'credential_unavailable';
}
