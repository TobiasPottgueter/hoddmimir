<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use InvalidArgumentException;

final readonly class PveExecutorAclEntry
{
    public function __construct(
        public string $path,
        public string $type,
        public string $identity,
        public string $roleId,
        public bool $propagate,
    ) {
        PveExecutorPermissionMatrixPath::assertPath($path);
        $identityValid = match ($type) {
            'token' => PveExecutorIdentityValidator::token($identity),
            'user' => PveExecutorIdentityValidator::owner($identity),
            'group' => PveExecutorIdentityValidator::group($identity),
            default => false,
        };
        if (!$identityValid
            || 1 !== \preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,189}\z/D', $roleId)) {
            throw new InvalidArgumentException('PVE ACL entry is invalid.');
        }
    }
}
