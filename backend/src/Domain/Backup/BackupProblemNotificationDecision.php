<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use InvalidArgumentException;

final readonly class BackupProblemNotificationDecision
{
    public function __construct(
        public BackupProblemNotificationAction $action,
        public BackupProblemState $state,
        public ?BackupProblemCode $reportedCode,
        public int $reportedFailures,
    ) {
        $reports = BackupProblemNotificationAction::None !== $action;
        if ($reports !== (null !== $reportedCode)
            || $reports !== ($reportedFailures > 0)
            || (BackupProblemNotificationAction::Resolved === $action && $state->isOpen())
            || (\in_array($action, [
                BackupProblemNotificationAction::Opened,
                BackupProblemNotificationAction::Changed,
                BackupProblemNotificationAction::RepeatedFailure,
            ], true) && !$state->isOpen())
        ) {
            throw new InvalidArgumentException('The backup problem notification decision is inconsistent.');
        }
    }
}
