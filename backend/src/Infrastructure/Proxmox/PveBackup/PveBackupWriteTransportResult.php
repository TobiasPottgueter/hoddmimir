<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use InvalidArgumentException;

final readonly class PveBackupWriteTransportResult
{
    public function __construct(
        public PveBackupWriteTransportStatus $status,
        public mixed $data,
    ) {
        if ((PveBackupWriteTransportStatus::Responded === $status) !== (null !== $data)) {
            throw new InvalidArgumentException('The PVE backup write transport result is inconsistent.');
        }
    }

    public static function responded(mixed $data): self
    {
        if (null === $data) {
            throw new InvalidArgumentException('Use respondedWithoutData for a null PVE response.');
        }

        return new self(PveBackupWriteTransportStatus::Responded, $data);
    }

    public static function respondedWithoutData(): self
    {
        return new self(PveBackupWriteTransportStatus::Responded, new PveBackupNullResponse());
    }

    public static function ambiguous(): self
    {
        return new self(PveBackupWriteTransportStatus::Ambiguous, null);
    }

    public function decodedData(): mixed
    {
        return $this->data instanceof PveBackupNullResponse ? null : $this->data;
    }
}
