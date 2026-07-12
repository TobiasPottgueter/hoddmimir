<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PbsSnapshotObservation
{
    /** @var list<string> */ public array $files;
    public DateTimeImmutable $backupTime;

    /** @param list<string> $files */
    public function __construct(
        public PbsDatastoreId $datastore,
        public PbsNamespace $namespace,
        public PbsBackupType $backupType,
        public string $backupId,
        DateTimeImmutable $backupTime,
        array $files,
        public bool $protected,
        public ?string $comment,
        public ?string $fingerprint,
        public ?string $owner,
        public ?int $size,
        public ?PbsSnapshotVerification $verification,
    ) {
        if (!self::safeId($backupId) || null !== $size && $size < 0
            || null !== $owner && (strlen($owner) < 3 || strlen($owner) > 64)) {
            throw new InvalidArgumentException('The PBS snapshot observation is invalid.');
        }
        if (null !== $comment && (strlen($comment) > 128 || self::containsControlCharacter($comment))) {
            throw new InvalidArgumentException('The PBS snapshot observation is invalid.');
        }
        $unique = [];
        foreach ($files as $file) {
            // @phpstan-ignore function.alreadyNarrowedType (enforce the declared runtime boundary)
            if (!is_string($file) || !self::safeId($file) || isset($unique[$file])) {
                throw new InvalidArgumentException('The PBS snapshot files are invalid.');
            }
            $unique[$file] = true;
        }
        ksort($unique, SORT_STRING);
        $this->files = array_keys($unique);
        $this->backupTime = $backupTime->setTimezone(new DateTimeZone('UTC'));
        if ((int) $this->backupTime->format('U') < 1) {
            throw new InvalidArgumentException('The PBS snapshot time is invalid.');
        }
    }

    public function groupKey(): string
    {
        return $this->datastore->value."\0".$this->namespace->value."\0".$this->backupType->value."\0".$this->backupId;
    }

    public function key(): string
    {
        return $this->groupKey()."\0".$this->backupTime->format('U');
    }

    private static function safeId(string $value): bool
    {
        $length = strlen($value);
        return $length >= 1 && $length <= 255
            && 1 === strspn($value[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_')
            && $length === strspn($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-');
    }

    private static function containsControlCharacter(string $value): bool
    {
        $length = strlen($value);
        for ($index = 0; $index < $length; ++$index) {
            $code = ord($value[$index]);
            if ($code < 32 || 127 === $code) {
                return true;
            }
        }
        return false;
    }
}
