<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

interface MatrixWebhook
{
    /** @throws MatrixWebhookFailure */
    public function send(string $eventId, string $body): void;
}
