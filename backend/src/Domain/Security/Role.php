<?php

declare(strict_types=1);

namespace App\Domain\Security;

enum Role: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';
}
