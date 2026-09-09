<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use App\Domain\Backup\BackupProblemCode;

final readonly class PrePostProblem
{
    public function __construct(
        public BackupProblemCode $code,
        public string $detailCode,
    ) {
    }
}
