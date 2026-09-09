<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum SelectionScope: string
{
    case Global = 'global';
    case Connection = 'connection';
    case Cluster = 'cluster';
    case Node = 'node';
    case Guest = 'guest';
}
