<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use InvalidArgumentException;

final readonly class BackupOperationCommand
{
    public string $payloadHash;

    public function __construct(
        public BackupOperationCommandType $type,
        public string $subjectId,
        public string $policyId,
        public string $guestId,
        public int $expectedRevision,
        public string $idempotencyKey,
        public string $correlationId,
    ) {
        if (16 !== \strlen($subjectId) || 16 !== \strlen($policyId) || 16 !== \strlen($guestId) || 16 !== \strlen($correlationId)
            || $expectedRevision < 0 || 1 !== \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $idempotencyKey)) {
            throw new InvalidArgumentException('The backup-operation command is invalid.');
        }
        $this->payloadHash = \hash('sha256', \implode("\0", [
            $type->value, \bin2hex($subjectId), \bin2hex($policyId), \bin2hex($guestId), (string) $expectedRevision,
        ]), true);
    }
}
