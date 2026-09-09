<?php

declare(strict_types=1);

namespace App\Application\Inventory\Pve;

use InvalidArgumentException;

final readonly class PveCoreInstallationBinding
{
    public function __construct(
        public PveCoreBindingKind $kind,
        public string $value,
    ) {
        if (!PveCoreTextValidator::isPrintableBinding($this->value)) {
            throw new InvalidArgumentException('The PVE installation binding must be printable ASCII.');
        }
    }

    public function topology(): string
    {
        return PveCoreBindingKind::Cluster === $this->kind ? 'clustered' : 'standalone';
    }
}
