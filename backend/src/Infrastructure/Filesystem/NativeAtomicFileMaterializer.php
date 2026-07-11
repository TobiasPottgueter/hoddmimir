<?php

declare(strict_types=1);

namespace App\Infrastructure\Filesystem;

use RuntimeException;
use Throwable;

final readonly class NativeAtomicFileMaterializer implements AtomicFileMaterializer
{
    public function materialize(
        string $directory,
        string $filename,
        string $contents,
        int $directoryMode,
        int $fileMode,
    ): string {
        if (is_link($directory)) {
            throw new RuntimeException('The materialization directory is unsafe.');
        }

        if (!is_dir($directory) && !mkdir($directory, $directoryMode, true) && !is_dir($directory)) {
            throw new RuntimeException('The materialization directory could not be created.');
        }

        if (!chmod($directory, $directoryMode)) {
            throw new RuntimeException('The materialization directory could not be secured.');
        }

        $path = $directory.'/'.$filename;
        if (file_exists($path)) {
            if (is_link($path) || !is_file($path) || file_get_contents($path) !== $contents) {
                throw new RuntimeException('The existing materialized file is unsafe.');
            }

            if (!chmod($path, $fileMode)) {
                throw new RuntimeException('The materialized file could not be secured.');
            }

            return $path;
        }

        try {
            $suffix = bin2hex(random_bytes(16));
        } catch (Throwable) {
            throw new RuntimeException('The materialized file could not be created.');
        }

        $temporaryPath = $directory.'/.materialized-'.$suffix;
        $handle = @fopen($temporaryPath, 'x+b');
        if (false === $handle) {
            throw new RuntimeException('The materialized file could not be created.');
        }

        try {
            if (!chmod($temporaryPath, $fileMode)) {
                throw new RuntimeException('The materialized file could not be written.');
            }

            $remaining = $contents;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (false === $written || 0 === $written) {
                    throw new RuntimeException('The materialized file could not be written.');
                }
                $remaining = substr($remaining, $written);
            }

            if (!fflush($handle)) {
                throw new RuntimeException('The materialized file could not be written.');
            }
        } catch (Throwable $failure) {
            @unlink($temporaryPath);
            throw $failure;
        } finally {
            fclose($handle);
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('The materialized file could not be installed.');
        }

        return $path;
    }
}
