<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MariaDb;

use App\Application\Inventory\InventoryIdentifier;
use App\Application\Inventory\InventoryIdentifierGenerator;
use App\Domain\Shared\Clock;
use RuntimeException;

final readonly class SystemUuidV7InventoryIdentifierGenerator implements InventoryIdentifierGenerator
{
    private const int MAXIMUM_UNIX_MILLISECONDS = 281_474_976_710_655;

    public function __construct(private Clock $clock)
    {
    }

    public function generate(): InventoryIdentifier
    {
        $now = $this->clock->now();
        $milliseconds = ((int) $now->format('U') * 1000) + intdiv((int) $now->format('u'), 1000);
        if ($milliseconds < 0 || $milliseconds > self::MAXIMUM_UNIX_MILLISECONDS) {
            throw new RuntimeException('The current instant is outside the UUIDv7 timestamp range.');
        }

        $timestamp = pack('Nn', intdiv($milliseconds, 65_536), $milliseconds & 0xffff);
        $random = random_bytes(10);
        $random[0] = chr((ord($random[0]) & 0x0f) | 0x70);
        $random[2] = chr((ord($random[2]) & 0x3f) | 0x80);

        return new InventoryIdentifier($timestamp.$random);
    }
}
