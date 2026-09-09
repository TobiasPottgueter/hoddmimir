<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Logging;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use App\Infrastructure\Logging\SensitiveDataRedactor;
use App\Infrastructure\Security\EncryptionKeyRing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SensitiveParameterValue;
use Stringable;

final class SensitiveDataRedactorTest extends TestCase
{
    public function testItRecursivelyRedactsSensitiveKeysValuesAndThrowables(): void
    {
        $redactor = new SensitiveDataRedactor();
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        try {
            $redacted = $redactor->redactContext([
                'authorization' => 'Bearer CONTEXT-SENTINEL',
                'nested' => [
                    'apiToken' => 'CONTEXT-SENTINEL',
                    'pwd' => 'CONTEXT-SENTINEL',
                    'otp' => 'CONTEXT-SENTINEL',
                    'safe' => 'preserved',
                ],
                'headerLine' => 'Authorization: PVEAPIToken=root@pam!test=CONTEXT-SENTINEL',
                'url' => 'https://example.invalid/path?page=1&token=CONTEXT-SENTINEL&sort=name',
                'plaintextObject' => PlaintextSecret::fromString('CONTEXT-SENTINEL'),
                'encryptedObject' => EncryptedSecret::fromEncoded('CONTEXT-SENTINEL'),
                'keyRingObject' => self::keyRing(),
                'sensitiveParameterValue' => new SensitiveParameterValue('CONTEXT-SENTINEL'),
                'exception' => new RuntimeException('password=CONTEXT-SENTINEL', 42),
                'unknownObject' => new ExplodingStringable(),
                'resource' => $resource,
                'integer' => 12,
                'boolean' => true,
                'null' => null,
            ]);
        } finally {
            fclose($resource);
        }

        self::assertSame(SensitiveDataRedactor::REDACTED, $redacted['authorization']);
        self::assertIsArray($redacted['nested']);
        $nested = $redacted['nested'];
        self::assertSame(SensitiveDataRedactor::REDACTED, $nested['apiToken']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $nested['pwd']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $nested['otp']);
        self::assertSame('preserved', $nested['safe']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $redacted['plaintextObject']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $redacted['encryptedObject']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $redacted['keyRingObject']);
        self::assertSame(SensitiveDataRedactor::REDACTED, $redacted['sensitiveParameterValue']);
        self::assertIsArray($redacted['exception']);
        $exception = $redacted['exception'];
        self::assertSame(RuntimeException::class, $exception['type']);
        self::assertSame(42, $exception['code']);
        self::assertIsString($exception['message']);
        self::assertStringContainsString(SensitiveDataRedactor::REDACTED, $exception['message']);
        self::assertSame('[OBJECT '.ExplodingStringable::class.']', $redacted['unknownObject']);
        self::assertSame('[RESOURCE]', $redacted['resource']);
        self::assertSame(12, $redacted['integer']);
        self::assertTrue($redacted['boolean']);
        self::assertNull($redacted['null']);

        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('CONTEXT-SENTINEL', $encoded);
        self::assertStringContainsString('[REDACTED]', $encoded);
    }

    public function testItRedactsSupportedSecretPatternsInFreeFormMessages(): void
    {
        $redactor = new SensitiveDataRedactor();
        $messages = [
            'Authorization: Bearer MESSAGE-SENTINEL',
            'Proxy-Authorization=Basic MESSAGE-SENTINEL',
            'Cookie: session=MESSAGE-SENTINEL',
            'Set-Cookie: session=MESSAGE-SENTINEL; Secure',
            'PVEAPIToken=root@pam!token=MESSAGE-SENTINEL',
            'PBSAPIToken=root@pam!token=MESSAGE-SENTINEL',
            'PVEAuthCookie=MESSAGE-SENTINEL',
            'CSRFPreventionToken=MESSAGE-SENTINEL',
            'https://example.invalid/?password=MESSAGE-SENTINEL&page=1',
            'https://user:MESSAGE-SENTINEL@example.invalid/database',
            'https://example.invalid/?access_token=MESSAGE-SENTINEL&page=1',
            '{"secret":"MESSAGE-SENTINEL"}',
            '{"token_secret":"MESSAGE-SENTINEL"}',
            'ticket=MESSAGE-SENTINEL',
            'totp: MESSAGE-SENTINEL',
            'api_key=MESSAGE-SENTINEL',
        ];

        foreach ($messages as $message) {
            $redacted = $redactor->redactMessage($message);
            self::assertStringNotContainsString('MESSAGE-SENTINEL', $redacted, $message);
            self::assertStringContainsString(SensitiveDataRedactor::REDACTED, $redacted, $message);
        }

        self::assertSame('safe operational metadata', $redactor->redactMessage('safe operational metadata'));
    }

    public function testItRedactsTheCompleteCommaSeparatedAuthorizationHeaderValue(): void
    {
        $redacted = (new SensitiveDataRedactor())->redactMessage(
            'Authorization: Digest username=alice, response=AUTH-SENTINEL, nonce=NONCE-SENTINEL',
        );

        self::assertSame('Authorization: [REDACTED]', $redacted);
        self::assertStringNotContainsString('AUTH-SENTINEL', $redacted);
        self::assertStringNotContainsString('NONCE-SENTINEL', $redacted);

        $throwable = (new SensitiveDataRedactor())->redact(new RuntimeException(
            'Proxy-Authorization: Digest username=alice, response=AUTH-SENTINEL, nonce=NONCE-SENTINEL',
        ));
        self::assertIsArray($throwable);
        $encoded = json_encode($throwable, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('AUTH-SENTINEL', $encoded);
        self::assertStringNotContainsString('NONCE-SENTINEL', $encoded);
    }

    public function testItStopsAtTheMaximumRecursionDepth(): void
    {
        $value = 'leaf';
        for ($index = 0; $index < 10; ++$index) {
            $value = ['nested' => $value];
        }

        $redacted = (new SensitiveDataRedactor())->redact($value);
        $encoded = json_encode($redacted, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('[MAXIMUM_DEPTH_REACHED]', $encoded);
        self::assertStringNotContainsString('leaf', $encoded);
    }

    public function testUnknownObjectsAreNeverConvertedToStrings(): void
    {
        $object = new ExplodingStringable();

        self::assertSame(
            '[OBJECT '.ExplodingStringable::class.']',
            (new SensitiveDataRedactor())->redact($object),
        );
        self::assertFalse($object->wasInvoked);
    }

    private static function keyRing(): EncryptionKeyRing
    {
        return EncryptionKeyRing::fromJson(json_encode([
            'format' => 1,
            'revision' => 1,
            'primaryKeyId' => 'key_1',
            'keys' => [
                ['id' => 'key_1', 'material' => str_repeat('a', 64)],
            ],
        ], JSON_THROW_ON_ERROR));
    }
}

final class ExplodingStringable implements Stringable
{
    public bool $wasInvoked = false;

    public function __toString(): string
    {
        $this->wasInvoked = true;

        return 'OBJECT-STRING-SENTINEL';
    }
}
