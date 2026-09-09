<?php

declare(strict_types=1);

namespace App\Domain\Security;

enum Permission: string
{
    case InventoryRead = 'inventory.read';
    case BackupConfigurationManage = 'backup_configuration.manage';
    case BackupOperationsManage = 'backup_operations.manage';
    case AuditRead = 'audit.read';
    case SecurityManage = 'security.manage';
}
