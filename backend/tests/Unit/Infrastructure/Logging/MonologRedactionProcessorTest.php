<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Logging;

use App\Application\Security\PlaintextSecret;
use App\Infrastructure\Logging\MonologRedactionProcessor;
use App\Infrastructure\Logging\SensitiveDataRedactor;
use DateTimeImmutable;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MonologRedactionProcessorTest extends TestCase
{
    public function testItRedactsEveryStructuredRecordSurfaceBeforeJsonFormatting(): void
    {
        $record = new LogRecord(
            datetime: new DateTimeImmutable('2026-07-10T12:00:00+00:00'),
            channel: 'security',
            level: Level::Warning,
            message: 'Authorization: Bearer LOG-SENTINEL',
            context: [
                'safe' => 'connection-1',
                'token' => 'LOG-SENTINEL',
                'secretObject' => PlaintextSecret::fromString('LOG-SENTINEL'),
                'exception' => new RuntimeException('password=LOG-SENTINEL', 42),
            ],
            extra: [
                'requestUrl' => 'https://example.invalid/?access_token=LOG-SENTINEL',
                'cookie' => 'session=LOG-SENTINEL',
            ],
        );

        $processed = (new MonologRedactionProcessor(new SensitiveDataRedactor()))($record);
        $json = (new JsonFormatter())->format($processed);

        self::assertStringNotContainsString('LOG-SENTINEL', $json);
        self::assertStringContainsString('[REDACTED]', $json);
        self::assertStringContainsString('connection-1', $json);
        self::assertSame('Authorization: [REDACTED]', $processed->message);
        self::assertSame('connection-1', $processed->context['safe']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $processed->context['token']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $processed->context['secretObject']);
        self::assertIsArray($processed->context['exception']);
        self::assertSame(RuntimeException::class, $processed->context['exception']['type']);
        self::assertSame(42, $processed->context['exception']['code']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $processed->extra['cookie']);

        self::assertStringContainsString('LOG-SENTINEL', $record->message);
        self::assertSame('LOG-SENTINEL', $record->context['token']);
    }
}
