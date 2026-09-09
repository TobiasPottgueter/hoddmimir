<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\LogRecord;

final readonly class MonologRedactionProcessor
{
    public function __construct(private SensitiveDataRedactor $redactor)
    {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redactMessage($record->message),
            context: $this->redactor->redactContext($record->context),
            extra: $this->redactor->redactContext($record->extra),
        );
    }
}
