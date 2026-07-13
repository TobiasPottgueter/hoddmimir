<?php

declare(strict_types=1);

namespace App\Application\Security\Audit;

enum AuditEventType: string
{
    case FirstAdminCreated = 'first_admin_created';
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserDisabled = 'user_disabled';
    case RoleAssigned = 'role_assigned';
    case RoleRemoved = 'role_removed';
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case SessionCreated = 'session_created';
    case SessionRevoked = 'session_revoked';
    case TargetCreated = 'target_created';
    case TargetUpdated = 'target_updated';
    case TargetEnabled = 'target_enabled';
    case TargetDisabled = 'target_disabled';
    case PolicyCreated = 'policy_created';
    case PolicyUpdated = 'policy_updated';
    case PolicyEnabled = 'policy_enabled';
    case PolicyDisabled = 'policy_disabled';
    case SelectionUpserted = 'selection_upserted';
    case SelectionDisabled = 'selection_disabled';
    case GuestOverrideUpserted = 'guest_override_upserted';
    case GuestOverrideDisabled = 'guest_override_disabled';
    case ConnectionCreated = 'connection_created';
    case ConnectionUpdated = 'connection_updated';
    case ConnectionEnabled = 'connection_enabled';
    case ConnectionDisabled = 'connection_disabled';
    case EndpointCreated = 'endpoint_created';
    case EndpointUpdated = 'endpoint_updated';
    case EndpointDisabled = 'endpoint_disabled';
    case CredentialRotated = 'credential_rotated';
    case ManualBackupRequested = 'manual_backup_requested';
    case BackupCancelRequested = 'backup_cancel_requested';
}
