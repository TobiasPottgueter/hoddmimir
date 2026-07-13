<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Security\Audit\AuditEventType;

enum SecurityCommandType: string
{
    case UserCreate = 'user.create';
    case UserUpdate = 'user.update';
    case UserDisable = 'user.disable';
    case UserRolesReplace = 'user.roles.replace';

    private const array AUDIT_TYPES = [
        'user.create' => AuditEventType::UserCreated,
        'user.update' => AuditEventType::UserUpdated,
        'user.disable' => AuditEventType::UserDisabled,
        'user.roles.replace' => AuditEventType::RoleAssigned,
    ];

    public function auditType(): AuditEventType
    {
        return self::AUDIT_TYPES[$this->value];
    }
}
