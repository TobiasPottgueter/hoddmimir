<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class MaterializationDirectoryValidator
{
    public static function dedicatedChildPath(string $baseDirectory, string $leafDirectory): ?string
    {
        if (
            !str_starts_with($baseDirectory, '/')
            || '/' === $baseDirectory
            || str_contains($baseDirectory, "\0")
            || str_ends_with($baseDirectory, '/')
        ) {
            return null;
        }

        $baseSegments = explode('/', substr($baseDirectory, 1));
        if (count($baseSegments) < 2) {
            return null;
        }

        foreach ($baseSegments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment) {
                return null;
            }
        }

        if (1 !== preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $leafDirectory)) {
            return null;
        }

        return $baseDirectory.'/'.$leafDirectory;
    }
}
