<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

enum ExecutorEvidenceRefreshFailureCode: string
{
    case Authentication = 'authentication';
    case CredentialUnavailable = 'credential_unavailable';
    case PermissionDenied = 'permission_denied';
    case Tls = 'tls';
    case Transport = 'transport';
    case RemoteUnavailable = 'remote_unavailable';
    case InvalidResponse = 'invalid_response';
    case ConfigurationChanged = 'configuration_changed';
}
