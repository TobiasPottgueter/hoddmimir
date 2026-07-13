<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

use RuntimeException;

final class MatrixWebhookFailure extends RuntimeException
{
    public function __construct(public readonly MatrixWebhookFailureCode $failureCode)
    {
        parent::__construct('The Matrix notification webhook delivery failed.');
    }
}
