<?php

declare(strict_types=1);

namespace App\Domain\Policy;

enum Compression: string
{
    case None = '0';
    case Gzip = 'gzip';
    case Lzo = 'lzo';
    case Zstd = 'zstd';
}
