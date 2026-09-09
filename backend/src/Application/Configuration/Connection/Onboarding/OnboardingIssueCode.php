<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

enum OnboardingIssueCode: string
{
    case TlsVerificationFailed = 'tls_verification_failed';
    case TlsFingerprintMismatch = 'tls_fingerprint_mismatch';
    case AuthenticationFailed = 'authentication_failed';
    case RemoteUnavailable = 'remote_unavailable';
    case InvalidRemoteResponse = 'invalid_remote_response';
    case ProductMismatch = 'product_mismatch';
    case VersionEvidenceMismatch = 'version_evidence_mismatch';
    case UnsupportedVersion = 'unsupported_version';
    case RoleMissing = 'role_missing';
    case RoleDefinitionMismatch = 'role_definition_mismatch';
    case CredentialMissing = 'credential_missing';
    case TokenIdentityInvalid = 'token_identity_invalid';
    case TokenIdentitiesNotSeparated = 'token_identities_not_separated';
    case RequiredPermissionMissing = 'required_permission_missing';
    case PermissionNotPropagated = 'permission_not_propagated';
    case NoAccessOverride = 'no_access_override';
    case ForbiddenPermissionPresent = 'forbidden_permission_present';
    case AdditionalReadOnlyPermission = 'additional_read_only_permission';
    case ApplicationPermissionDenied = 'application_permission_denied';
}
