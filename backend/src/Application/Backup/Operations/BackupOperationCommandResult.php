<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use InvalidArgumentException;

final readonly class BackupOperationCommandResult
{
    public function __construct(
        public BackupOperationCommandStatus $status,
        public ?int $revision,
        public ?string $blocker = null,
    ) {
        if ((BackupOperationCommandStatus::Blocked === $status) !== (null !== $blocker)
            || (BackupOperationCommandStatus::Blocked === $status) === (null !== $revision)) {
            throw new InvalidArgumentException('The backup-operation result is invalid.');
        }
    }
}
