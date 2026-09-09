<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Application\Security\Audit\AuditEventType;

enum ConfigurationCommandType: string
{
    case TargetCreate = 'target.create';
    case TargetUpdate = 'target.update';
    case TargetEnable = 'target.enable';
    case TargetDisable = 'target.disable';
    case PolicyCreate = 'policy.create';
    case PolicyUpdate = 'policy.update';
    case PolicyEnable = 'policy.enable';
    case PolicyDisable = 'policy.disable';
    case SelectionUpsert = 'selection.upsert';
    case SelectionDisable = 'selection.disable';
    case GuestOverrideUpsert = 'guest_override.upsert';
    case GuestOverrideDisable = 'guest_override.disable';
    case ConnectionCreate = 'connection.create';
    case ConnectionUpdate = 'connection.update';
    case ConnectionEnable = 'connection.enable';
    case ConnectionDisable = 'connection.disable';
    case EndpointCreate = 'endpoint.create';
    case EndpointUpdate = 'endpoint.update';
    case EndpointDisable = 'endpoint.disable';
    case CredentialRotate = 'credential.rotate';

    public function auditType(): AuditEventType
    {
        return self::AUDIT_TYPES[$this->value];
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPES[$this->value];
    }

    private const array AUDIT_TYPES = [
        'target.create'=>AuditEventType::TargetCreated,'target.update'=>AuditEventType::TargetUpdated,
        'target.enable'=>AuditEventType::TargetEnabled,'target.disable'=>AuditEventType::TargetDisabled,
        'policy.create'=>AuditEventType::PolicyCreated,'policy.update'=>AuditEventType::PolicyUpdated,
        'policy.enable'=>AuditEventType::PolicyEnabled,'policy.disable'=>AuditEventType::PolicyDisabled,
        'selection.upsert'=>AuditEventType::SelectionUpserted,'selection.disable'=>AuditEventType::SelectionDisabled,
        'guest_override.upsert'=>AuditEventType::GuestOverrideUpserted,'guest_override.disable'=>AuditEventType::GuestOverrideDisabled,
        'connection.create'=>AuditEventType::ConnectionCreated,'connection.update'=>AuditEventType::ConnectionUpdated,
        'connection.enable'=>AuditEventType::ConnectionEnabled,'connection.disable'=>AuditEventType::ConnectionDisabled,
        'endpoint.create'=>AuditEventType::EndpointCreated,'endpoint.update'=>AuditEventType::EndpointUpdated,
        'endpoint.disable'=>AuditEventType::EndpointDisabled,'credential.rotate'=>AuditEventType::CredentialRotated,
    ];
    private const array SUBJECT_TYPES = [
        'target.create'=>'target','target.update'=>'target','target.enable'=>'target','target.disable'=>'target',
        'policy.create'=>'policy','policy.update'=>'policy','policy.enable'=>'policy','policy.disable'=>'policy',
        'selection.upsert'=>'selection','selection.disable'=>'selection',
        'guest_override.upsert'=>'guest_override','guest_override.disable'=>'guest_override',
        'connection.create'=>'connection','connection.update'=>'connection','connection.enable'=>'connection','connection.disable'=>'connection',
        'endpoint.create'=>'connection','endpoint.update'=>'connection','endpoint.disable'=>'connection','credential.rotate'=>'connection',
    ];
}
