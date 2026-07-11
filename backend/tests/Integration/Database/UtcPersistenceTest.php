<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;

final class UtcPersistenceTest extends DatabaseTestCase
{
    public function testDoctrineSessionIsPinnedToUtc(): void
    {
        self::assertSame('+00:00', $this->connection()->fetchOne('SELECT @@SESSION.time_zone'));
    }

    public function testMariaDbRoundTripPreservesUtcMicrosecondsAcrossDstBoundary(): void
    {
        $this->connection()->executeStatement(
            'CREATE TEMPORARY TABLE utc_round_trip (observed_at DATETIME(6) NOT NULL) ENGINE=InnoDB',
        );

        $instant = new DateTimeImmutable('2026-03-29T00:59:59.123456+00:00');
        $databaseValue = $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');

        $this->connection()->insert('utc_round_trip', ['observed_at' => $databaseValue]);

        $storedValue = $this->connection()->fetchOne('SELECT observed_at FROM utc_round_trip');
        self::assertSame('2026-03-29 00:59:59.123456', $storedValue);

        $restored = DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s.u',
            (string) $storedValue,
            new DateTimeZone('UTC'),
        );

        self::assertInstanceOf(DateTimeImmutable::class, $restored);
        self::assertSame($instant->format('U.u'), $restored->format('U.u'));
    }
}
