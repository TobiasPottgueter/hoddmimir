<?php

declare(strict_types=1);

namespace App\Application\Inventory\Connection;

enum EndpointReadFailureCode: string
{
    case Transport = 'transport';
    case Tls = 'tls';
    case UnsupportedProductOrVersion = 'unsupported_product_or_version';
    case RootUnusable = 'root_unusable';
    case WrongIdentity = 'wrong_identity';
    case CredentialUnavailable = 'credential_unavailable';
    case Authentication = 'authentication';
    case PermissionDenied = 'permission_denied';

    public function allowsEndpointFailover(): bool
    {
        return self::CredentialUnavailable !== $this
            && self::Authentication !== $this
            && self::PermissionDenied !== $this;
    }
}
