<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveTaskLogEntry
{
    public function __construct(
        public int $number,
        public string $text,
    ) {
        if ($number < 0) {
            throw new InvalidArgumentException('The PVE task log entry is invalid.');
        }
        if ('' === $text) {
            throw new InvalidArgumentException('The PVE task log entry is invalid.');
        }
        if (strlen($text) > 65_536) {
            throw new InvalidArgumentException('The PVE task log entry is invalid.');
        }
        $length = strlen($text);
        for ($index = 0; $index < $length; ++$index) {
            if ("\0" === $text[$index]) {
                throw new InvalidArgumentException('The PVE task log entry is invalid.');
            }
        }
    }
}
