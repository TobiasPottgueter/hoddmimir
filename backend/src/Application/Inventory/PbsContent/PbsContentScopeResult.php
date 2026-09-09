<?php

declare(strict_types=1);

namespace App\Application\Inventory\PbsContent;

use App\Application\Proxmox\Pbs\PbsDatastoreId;
use App\Application\Proxmox\Pbs\PbsNamespace;
use InvalidArgumentException;

final readonly class PbsContentScopeResult
{
    public function __construct(
        public PbsContentScopeType $type,
        public PbsDatastoreId $datastore,
        public ?PbsNamespace $namespace,
        public PbsContentScopeStatus $status,
        public int $rowsRead,
        public ?string $errorCode = null,
    ) {
        if ($rowsRead < 0
            || PbsContentScopeType::Namespaces === $type && null !== $namespace
            || PbsContentScopeType::Snapshots === $type && null === $namespace
            || PbsContentScopeStatus::Complete === $status && null !== $errorCode
            || PbsContentScopeStatus::Complete !== $status
                && (null === $errorCode || !self::errorCode($errorCode))) {
            throw new InvalidArgumentException('The PBS content scope result is invalid.');
        }
    }

    public function key(): string
    {
        return $this->datastore->value."\0".(null === $this->namespace ? '@namespaces' : $this->namespace->value);
    }

    public function permitsAbsenceDecisions(): bool
    {
        return PbsContentScopeType::Snapshots === $this->type
            && PbsContentScopeStatus::Complete === $this->status;
    }

    private static function errorCode(string $value): bool
    {
        $length = strlen($value);
        return $length >= 1 && $length <= 64
            && $length === strspn($value, 'abcdefghijklmnopqrstuvwxyz0123456789_');
    }
}
