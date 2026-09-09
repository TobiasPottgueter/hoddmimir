<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class PveExecutorPermissionMatrixPath
{
    /** @param array<string, 0|1> $privileges */
    public function __construct(public string $path, public array $privileges)
    {
        self::assertPath($path);
        if (\count($privileges) > 256) {
            throw new InvalidArgumentException('PVE permission matrix entry is invalid.');
        }
        foreach ($privileges as $privilege => $propagate) {
            /** @phpstan-ignore function.alreadyNarrowedType (keep the external runtime boundary strict) */
            if (!\is_string($privilege)
                || 1 !== \preg_match('/\A[A-Za-z][A-Za-z0-9.]{0,127}\z/D', $privilege)
                /** @phpstan-ignore-next-line function.alreadyNarrowedType (keep the external runtime boundary strict) */
                || !\in_array($propagate, [0, 1], true)) {
                throw new InvalidArgumentException('PVE permission matrix entry is invalid.');
            }
        }
    }

    public static function assertPath(string $path): void
    {
        if (\strlen($path) > 2048
            || ('/' !== $path && ('' === $path || '/' !== $path[0] || '/' === $path[-1] || \str_contains($path, '//')))) {
            throw new InvalidArgumentException('PVE permission path is invalid.');
        }
        if (1 !== \preg_match('/\A\/(?:[A-Za-z0-9._@!:-]+(?:\/[A-Za-z0-9._@!:-]+)*)?\z/D', $path)) {
            throw new InvalidArgumentException('PVE permission path is invalid.');
        }
    }
}
