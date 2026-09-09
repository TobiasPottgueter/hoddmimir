<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

enum PveHttpMethod: string
{
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    public function isReadOnly(): bool
    {
        return self::Get === $this || self::Head === $this;
    }
}
