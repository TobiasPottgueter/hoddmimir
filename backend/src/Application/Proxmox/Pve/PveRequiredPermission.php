<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

enum PveRequiredPermission: string
{
    case SystemAudit = 'Sys.Audit';
    case VirtualMachineAudit = 'VM.Audit';
    case DatastoreAudit = 'Datastore.Audit';

    private const PATHS = [
        'Sys.Audit' => '/',
        'VM.Audit' => '/vms',
        'Datastore.Audit' => '/storage',
    ];

    public function path(): string
    {
        return self::PATHS[$this->value];
    }
}
