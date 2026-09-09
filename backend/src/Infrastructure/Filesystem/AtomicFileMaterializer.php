<?php

declare(strict_types=1);

namespace App\Infrastructure\Filesystem;

interface AtomicFileMaterializer
{
    public function materialize(
        string $directory,
        string $filename,
        string $contents,
        int $directoryMode,
        int $fileMode,
    ): string;
}
