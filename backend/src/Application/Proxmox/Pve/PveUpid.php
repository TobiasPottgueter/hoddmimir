<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveUpid
{
    public const MAXIMUM_LENGTH = 1024;
    private const PATTERN = '/\AUPID:([A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?):([0-9A-Fa-f]{8}):([0-9A-Fa-f]{8,9}):([0-9A-Fa-f]{8}):([^\s:\/\x00-\x1F\x7F]{1,64}):([^\s:\/\x00-\x1F\x7F]{0,255}):([^\s:\/\x00-\x1F\x7F]{1,255}):\z/D';

    private function __construct(
        public string $raw,
        public string $node,
        public int $pid,
        public int $processStart,
        public int $startTime,
        public string $type,
        public string $id,
        public string $user,
    ) {
    }

    public static function parse(string $raw): self
    {
        if (strlen($raw) > self::MAXIMUM_LENGTH || 1 !== preg_match(self::PATTERN, $raw, $matches)) {
            throw new InvalidArgumentException('Malformed PVE UPID.');
        }

        if ('vzdump' !== $matches[5]) {
            throw new InvalidArgumentException('The PVE UPID is not a vzdump task.');
        }

        return new self(
            $raw,
            $matches[1],
            intval($matches[2], 16),
            intval($matches[3], 16),
            intval($matches[4], 16),
            $matches[5],
            $matches[6],
            $matches[7],
        );
    }
}
