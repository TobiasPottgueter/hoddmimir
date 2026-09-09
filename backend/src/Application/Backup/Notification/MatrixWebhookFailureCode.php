<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

enum MatrixWebhookFailureCode: string
{
    case Configuration = 'configuration';
    case Transport = 'transport';
    case Rejected = 'rejected';
    case InvalidResponse = 'invalid_response';
}
