<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\PlaintextSecret;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameterValue;

final class SecretValueObjectsTest extends TestCase
{
    public function testPlaintextAccessIsExplicitAndPreservesExactBytes(): void
    {
        $secret = PlaintextSecret::fromString('  exact-secret  ');

        self::assertSame('  exact-secret  ', $secret->consume(static fn (string $value): string => $value));
    }

    public function testPlaintextRejectsInvalidValuesWithoutEchoingThem(): void
    {
        $invalidValues = [
            '',
            "secret\0sentinel",
            "secret\tsentinel",
            "secret\rsentinel",
            "secret\nsentinel",
            str_repeat('x', 4097),
        ];

        foreach ($invalidValues as $invalidValue) {
            try {
                PlaintextSecret::fromString($invalidValue);
                self::fail('Invalid plaintext was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertStringNotContainsString('sentinel', $exception->getMessage());
                if ($invalidValue !== '') {
                    self::assertStringNotContainsString($invalidValue, $exception->getMessage());
                }
            }
        }
    }

    public function testPlaintextDebugAndSerializationAreProtected(): void
    {
        $secret = PlaintextSecret::fromString('plaintext-sentinel');

        ob_start();
        var_dump($secret);
        $debugOutput = ob_get_clean();

        self::assertStringContainsString('[REDACTED]', $debugOutput);
        self::assertStringNotContainsString('plaintext-sentinel', $debugOutput);
        self::assertStringNotContainsString('plaintext-sentinel', var_export($secret, true));
        self::assertStringNotContainsString('plaintext-sentinel', json_encode($secret, JSON_THROW_ON_ERROR));

        try {
            serialize($secret);
            self::fail('Plaintext serialization was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Plaintext secrets cannot be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Plaintext secrets cannot be unserialized.');
        $secret->__unserialize([]);
    }

    public function testEncryptedValueAcceptsOnlyBoundedPrintableAscii(): void
    {
        $encrypted = EncryptedSecret::fromEncoded('hoddmimir-secret:1:key:nonce:ciphertext');
        self::assertSame('hoddmimir-secret:1:key:nonce:ciphertext', $encrypted->encoded());

        foreach (['', "cipher\ntext", "cipher\ttext", "cipher\xC3\xA4text", str_repeat('x', 8193)] as $invalidValue) {
            try {
                EncryptedSecret::fromEncoded($invalidValue);
                self::fail('Invalid encrypted value was accepted.');
            } catch (InvalidArgumentException $exception) {
                if ($invalidValue !== '') {
                    self::assertStringNotContainsString($invalidValue, $exception->getMessage());
                }
            }
        }
    }

    public function testEncryptedDebugAndSerializationAreProtected(): void
    {
        $secret = EncryptedSecret::fromEncoded('ciphertext-sentinel');

        ob_start();
        var_dump($secret);
        $debugOutput = ob_get_clean();

        self::assertStringContainsString('[REDACTED]', $debugOutput);
        self::assertStringNotContainsString('ciphertext-sentinel', $debugOutput);
        self::assertStringNotContainsString('ciphertext-sentinel', var_export($secret, true));
        self::assertStringNotContainsString('ciphertext-sentinel', json_encode($secret, JSON_THROW_ON_ERROR));

        try {
            serialize($secret);
            self::fail('Encrypted serialization was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Encrypted secrets cannot be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Encrypted secrets cannot be unserialized.');
        $secret->__unserialize([]);
    }

    public function testCorruptedInternalSensitiveValuesFailClosed(): void
    {
        $plaintext = $this->uninitializedWithSensitiveValue(PlaintextSecret::class, 'value', 123);
        try {
            $plaintext->consume(static fn (string $value): string => $value);
            self::fail('Corrupted plaintext storage was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Plaintext secret storage is invalid.', $exception->getMessage());
        }

        $encrypted = $this->uninitializedWithSensitiveValue(EncryptedSecret::class, 'encoded', 123);
        try {
            $encrypted->encoded();
            self::fail('Corrupted encrypted storage was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Encrypted secret storage is invalid.', $exception->getMessage());
        }
    }

    /**
     * @template TObject of object
     *
     * @param class-string<TObject> $className
     *
     * @return TObject
     */
    private function uninitializedWithSensitiveValue(string $className, string $propertyName, mixed $value): object
    {
        $reflection = new ReflectionClass($className);
        $object = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty($propertyName)->setValue($object, new SensitiveParameterValue($value));

        return $object;
    }
}
