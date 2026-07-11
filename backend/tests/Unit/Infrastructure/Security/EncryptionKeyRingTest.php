<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Security;

use App\Infrastructure\Security\EncryptionConfigurationException;
use App\Infrastructure\Security\EncryptionConfigurationFailure;
use App\Infrastructure\Security\EncryptionKeyRing;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameterValue;

final class EncryptionKeyRingTest extends TestCase
{
    public function testItLoadsAValidatedKeyRingWithoutExposingMaterial(): void
    {
        $keyRing = EncryptionKeyRing::fromJson(self::json(
            revision: 7,
            primaryKeyId: 'key_new',
            keys: [
                ['id' => 'key_old', 'material' => str_repeat('a', 64)],
                ['id' => 'key_new', 'material' => str_repeat('b', 64)],
            ],
        ), 7);

        self::assertSame(7, $keyRing->revision());
        self::assertSame('key_new', $keyRing->primaryKeyId());
        self::assertSame(str_repeat("\xBB", 32), $keyRing->primaryKey());
        self::assertSame(str_repeat("\xAA", 32), $keyRing->key('key_old'));
        self::assertNull($keyRing->key('missing'));
        self::assertTrue($keyRing->hasKey('key_old'));
        self::assertFalse($keyRing->hasKey('missing'));

        ob_start();
        var_dump($keyRing);
        $debugOutput = ob_get_clean();

        self::assertStringContainsString('key_new', $debugOutput);
        self::assertStringNotContainsString(str_repeat('a', 64), $debugOutput);
        self::assertStringNotContainsString(str_repeat('b', 64), $debugOutput);
        self::assertStringNotContainsString(str_repeat('a', 64), var_export($keyRing, true));
        self::assertStringNotContainsString(str_repeat('b', 64), var_export($keyRing, true));
    }

    public function testItAcceptsTheMaximumNumberOfUniqueKeys(): void
    {
        $keys = [];
        for ($index = 0; $index < 16; ++$index) {
            $keys[] = [
                'id' => sprintf('key_%02d', $index),
                'material' => str_pad(dechex($index + 1), 64, (string) (($index + 1) % 10), STR_PAD_LEFT),
            ];
        }

        $keyRing = EncryptionKeyRing::fromJson(self::json(1, 'key_00', $keys));

        self::assertSame('key_00', $keyRing->primaryKeyId());
    }

    public function testItRejectsMalformedJsonWithoutLeakingInput(): void
    {
        foreach (['', '{"material":"SENTINEL"', str_repeat('x', 65537)] as $json) {
            $this->assertConfigurationFailure($json, EncryptionConfigurationFailure::InvalidJson);
        }
    }

    public function testItRejectsInvalidTopLevelStructures(): void
    {
        $valid = self::document(1, 'key_1', [['id' => 'key_1', 'material' => str_repeat('a', 64)]]);
        $cases = [
            [],
            null,
            'not-an-object',
            $valid + ['extra' => true],
            array_diff_key($valid, ['keys' => true]),
            array_replace($valid, ['format' => 2]),
            array_replace($valid, ['format' => '1']),
            array_replace($valid, ['keys' => 'not-a-list']),
            array_replace($valid, ['keys' => ['not-a-list' => true]]),
            array_replace($valid, ['keys' => []]),
            array_replace($valid, ['keys' => array_fill(0, 17, ['id' => 'duplicate', 'material' => str_repeat('a', 64)])]),
        ];

        foreach ($cases as $case) {
            $this->assertConfigurationFailure(
                json_encode($case, JSON_THROW_ON_ERROR),
                EncryptionConfigurationFailure::InvalidStructure,
            );
        }
    }

    public function testItRejectsInvalidOrMismatchedRevisions(): void
    {
        foreach ([0, -1, 1.0, '1'] as $revision) {
            $document = self::document(1, 'key_1', [['id' => 'key_1', 'material' => str_repeat('a', 64)]]);
            $document['revision'] = $revision;
            $this->assertConfigurationFailure(
                json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                EncryptionConfigurationFailure::InvalidRevision,
            );
        }

        $this->assertConfigurationFailure(
            self::json(2, 'key_1', [['id' => 'key_1', 'material' => str_repeat('a', 64)]]),
            EncryptionConfigurationFailure::InvalidRevision,
            expectedRevision: 1,
        );
    }

    public function testItRejectsInvalidOrMissingPrimaryKeys(): void
    {
        $keys = [['id' => 'key_1', 'material' => str_repeat('a', 64)]];

        foreach (['', 'Uppercase', 'contains:colon', str_repeat('x', 33), 'missing'] as $primaryKeyId) {
            $this->assertConfigurationFailure(
                self::json(1, $primaryKeyId, $keys),
                EncryptionConfigurationFailure::InvalidPrimaryKey,
            );
        }

        $document = self::document(1, 'key_1', $keys);
        $document['primaryKeyId'] = 123;
        $this->assertConfigurationFailure(
            json_encode($document, JSON_THROW_ON_ERROR),
            EncryptionConfigurationFailure::InvalidPrimaryKey,
        );
    }

    public function testItRejectsInvalidKeyEntries(): void
    {
        $validMaterial = str_repeat('a', 64);
        $invalidEntries = [
            'not-an-object',
            ['list-entry'],
            ['id' => 'key_1'],
            ['id' => 'key_1', 'material' => $validMaterial, 'extra' => true],
            ['id' => 1, 'material' => $validMaterial],
            ['id' => '', 'material' => $validMaterial],
            ['id' => 'Uppercase', 'material' => $validMaterial],
            ['id' => 'key_1', 'material' => 123],
            ['id' => 'key_1', 'material' => str_repeat('A', 64)],
            ['id' => 'key_1', 'material' => str_repeat('a', 63)],
            ['id' => 'key_1', 'material' => str_repeat('g', 64)],
        ];

        foreach ($invalidEntries as $entry) {
            $this->assertConfigurationFailure(
                self::json(1, 'key_1', [$entry]),
                EncryptionConfigurationFailure::InvalidKey,
            );
        }
    }

    public function testItRejectsDuplicateKeyIdsAndMaterial(): void
    {
        $materialA = str_repeat('a', 64);
        $materialB = str_repeat('b', 64);

        foreach ([
            [
                ['id' => 'key_1', 'material' => $materialA],
                ['id' => 'key_1', 'material' => $materialB],
            ],
            [
                ['id' => 'key_1', 'material' => $materialA],
                ['id' => 'key_2', 'material' => $materialA],
            ],
        ] as $keys) {
            $this->assertConfigurationFailure(
                self::json(1, 'key_1', $keys),
                EncryptionConfigurationFailure::DuplicateKey,
            );
        }
    }

    public function testKeyIdValidationIsStrict(): void
    {
        self::assertTrue(EncryptionKeyRing::isValidKeyId('a'));
        self::assertTrue(EncryptionKeyRing::isValidKeyId('key_2026-07'));
        self::assertFalse(EncryptionKeyRing::isValidKeyId(''));
        self::assertFalse(EncryptionKeyRing::isValidKeyId('Key'));
        self::assertFalse(EncryptionKeyRing::isValidKeyId(str_repeat('x', 33)));
    }

    public function testSerializationIsRejected(): void
    {
        $keyRing = EncryptionKeyRing::fromJson(self::json(
            1,
            'key_1',
            [['id' => 'key_1', 'material' => str_repeat('a', 64)]],
        ));

        try {
            serialize($keyRing);
            self::fail('Keyring serialization was accepted.');
        } catch (LogicException $exception) {
            self::assertSame('Encryption key rings cannot be serialized.', $exception->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Encryption key rings cannot be unserialized.');
        $keyRing->__unserialize([]);
    }

    public function testCorruptedInternalKeyMaterialFailsClosed(): void
    {
        $reflection = new ReflectionClass(EncryptionKeyRing::class);
        $keyRing = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('revision')->setValue($keyRing, 1);
        $reflection->getProperty('primaryKeyId')->setValue($keyRing, 'key_1');
        $reflection->getProperty('keys')->setValue($keyRing, [
            'key_1' => new SensitiveParameterValue(123),
        ]);

        try {
            $keyRing->primaryKey();
            self::fail('Corrupted key material was accepted.');
        } catch (EncryptionConfigurationException $exception) {
            self::assertSame(EncryptionConfigurationFailure::InvalidKey, $exception->failure);
            self::assertSame('Encryption key configuration is invalid.', $exception->getMessage());
        }
    }

    private function assertConfigurationFailure(
        string $json,
        EncryptionConfigurationFailure $expectedFailure,
        ?int $expectedRevision = null,
    ): void {
        try {
            EncryptionKeyRing::fromJson($json, $expectedRevision);
            self::fail('Invalid keyring was accepted.');
        } catch (EncryptionConfigurationException $exception) {
            self::assertSame($expectedFailure, $exception->failure);
            self::assertSame('Encryption key configuration is invalid.', $exception->getMessage());
            self::assertStringNotContainsString('SENTINEL', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /**
     * @param list<mixed> $keys
     */
    private static function json(int $revision, string $primaryKeyId, array $keys): string
    {
        return json_encode(self::document($revision, $primaryKeyId, $keys), JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<mixed> $keys
     *
     * @return array{format: int, revision: int, primaryKeyId: string, keys: list<mixed>}
     */
    private static function document(int $revision, string $primaryKeyId, array $keys): array
    {
        return [
            'format' => 1,
            'revision' => $revision,
            'primaryKeyId' => $primaryKeyId,
            'keys' => $keys,
        ];
    }
}
