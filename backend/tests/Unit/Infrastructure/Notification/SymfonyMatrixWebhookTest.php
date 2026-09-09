<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Notification;

use App\Application\Backup\Notification\MatrixWebhookFailure;
use App\Application\Backup\Notification\MatrixWebhookFailureCode;
use App\Infrastructure\Notification\SymfonyMatrixWebhook;
use App\Infrastructure\Notification\SecretFileMatrixWebhook;
use App\Infrastructure\Notification\ConfiguredBackupNotificationDeliveryGate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyMatrixWebhookTest extends TestCase
{
    public function testPostsOldCompatibleBodyAndChannelWithIdempotencyHeader(): void
    {
        $requests = 0;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            self::assertSame('POST', $method);
            self::assertSame('https://matrix.example.test/hook/secret', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertTrue($options['verify_host']);
            self::assertTrue($options['verify_peer']);
            $headers = $options['headers'];
            self::assertIsArray($headers);
            self::assertContains('Idempotency-Key: eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', $headers);
            $body = $options['body'];
            self::assertIsString($body);
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            self::assertSame(['body' => 'Backup fehlgeschlagen – Versuch Nr. 7', 'channel' => 'proxmox-backup'], $payload);

            return new MockResponse('', ['http_code' => 204]);
        });

        (new SymfonyMatrixWebhook(
            $client,
            'https://matrix.example.test/hook/secret',
            'proxmox-backup',
        ))->send(str_repeat('e', 32), 'Backup fehlgeschlagen – Versuch Nr. 7');

        self::assertSame(1, $requests);
    }

    public function testSecretFileWrapperReadsUrlOnlyWhenSending(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hoddmimir-matrix-');
        self::assertIsString($path);
        try {
            self::assertNotFalse(file_put_contents($path, "https://matrix.example.test/hook/secret\n"));
            $client = new MockHttpClient(static fn (string $method, string $url): MockResponse => new MockResponse(
                '',
                ['http_code' => 'POST' === $method && 'https://matrix.example.test/hook/secret' === $url ? 204 : 500],
            ));
            $webhook = new SecretFileMatrixWebhook($client, $path, 'channel');
            self::assertTrue($webhook->isValid());
            $webhook->send(str_repeat('e', 32), 'body');
        } finally {
            @unlink($path);
        }
    }

    public function testMissingSecretFileFailsWithNonSensitiveConfigurationCode(): void
    {
        $webhook = new SecretFileMatrixWebhook(
            new MockHttpClient(),
            '/definitely/missing/hoddmimir-matrix-url',
            'channel',
        );
        self::assertFalse($webhook->isValid());
        try {
            $webhook->send(str_repeat('e', 32), 'body');
            self::fail('Missing Matrix secret was accepted.');
        } catch (MatrixWebhookFailure $failure) {
            self::assertSame(MatrixWebhookFailureCode::Configuration, $failure->failureCode);
            self::assertStringNotContainsString('/definitely/missing', $failure->getMessage());
        }
    }

    public function testDeliveryGateReflectsExplicitConfiguration(): void
    {
        self::assertTrue((new ConfiguredBackupNotificationDeliveryGate(true))->enabled());
        self::assertFalse((new ConfiguredBackupNotificationDeliveryGate(false))->enabled());
    }

    #[DataProvider('failureProvider')]
    public function testMapsTransportAndHttpFailuresWithoutExposingResponse(
        string $scenario,
        MatrixWebhookFailureCode $expected,
    ): void {
        $client = 'transport' === $scenario
            ? new MockHttpClient(static function (): never {
                throw new TransportException('sensitive transport detail');
            })
            : new MockHttpClient(new MockResponse('remote body', ['http_code' => 500]));
        $webhook = new SymfonyMatrixWebhook(
            $client,
            'https://matrix.example.test/hook/secret',
            'proxmox-backup',
        );

        try {
            $webhook->send(str_repeat('e', 32), 'Failure body');
            self::fail('Webhook failure was accepted.');
        } catch (MatrixWebhookFailure $failure) {
            self::assertSame($expected, $failure->failureCode);
            self::assertStringNotContainsString('secret', $failure->getMessage());
            self::assertStringNotContainsString('remote body', $failure->getMessage());
        }
    }

    /** @return iterable<string, array{string, MatrixWebhookFailureCode}> */
    public static function failureProvider(): iterable
    {
        yield 'transport' => ['transport', MatrixWebhookFailureCode::Transport];
        yield 'rejected' => ['rejected', MatrixWebhookFailureCode::Rejected];
    }

    #[DataProvider('invalidConfigurationProvider')]
    public function testConfigurationRequiresHttpsAndClosedBounds(string $url, string $channel, float $timeout): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SymfonyMatrixWebhook(new MockHttpClient(), $url, $channel, $timeout);
    }

    /** @return iterable<string, array{string, string, float}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'http' => ['http://matrix.example.test/hook', 'channel', 10.0];
        yield 'credential in URL' => ['https://user:secret@matrix.example.test/hook', 'channel', 10.0];
        yield 'fragment' => ['https://matrix.example.test/hook#secret', 'channel', 10.0];
        yield 'channel' => ['https://matrix.example.test/hook', 'Bad channel', 10.0];
        yield 'short timeout' => ['https://matrix.example.test/hook', 'channel', 0.9];
        yield 'long timeout' => ['https://matrix.example.test/hook', 'channel', 30.1];
    }

    public function testSecretFilePathMustBeAbsolute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SecretFileMatrixWebhook(new MockHttpClient(), 'relative', 'channel');
    }

    #[DataProvider('invalidMessageProvider')]
    public function testInvalidMessageFailsBeforeHttp(string $eventId, string $body): void
    {
        $webhook = new SymfonyMatrixWebhook(
            new MockHttpClient(static fn (): never => throw new \LogicException('HTTP must not be called.')),
            'https://matrix.example.test/hook',
            'channel',
        );
        try {
            $webhook->send($eventId, $body);
            self::fail('Invalid webhook message was accepted.');
        } catch (MatrixWebhookFailure $failure) {
            self::assertSame(MatrixWebhookFailureCode::Configuration, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMessageProvider(): iterable
    {
        yield 'event ID' => ['bad', 'body'];
        yield 'body empty' => [str_repeat('e', 32), ' '];
        yield 'body too large' => [str_repeat('e', 32), str_repeat('x', 16_385)];
    }
}
