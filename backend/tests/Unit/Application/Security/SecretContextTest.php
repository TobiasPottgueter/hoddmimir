<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Security;

use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SecretContextTest extends TestCase
{
    public function testItBuildsStablePurposeAndCredentialBoundAdditionalData(): void
    {
        $context = SecretContext::forCredential('credential-018f:primary', SecretPurpose::PveCollectorToken);

        self::assertSame(SecretPurpose::PveCollectorToken, $context->purpose());
        self::assertSame(
            "hoddmimir-secret\0v1\0key_2026_07\0pve_collector_token\0credential-018f:primary\0token-secret",
            $context->additionalAuthenticatedData(1, 'key_2026_07'),
        );
    }

    public function testBinaryCredentialIdsHaveOneCanonicalLowercaseHexRepresentation(): void
    {
        $binary = hex2bin('00ABCDEF0123456789ABCDEF012345FF');
        self::assertIsString($binary);

        $context = SecretContext::forBinaryCredentialId($binary, SecretPurpose::PveCollectorToken);
        self::assertSame(
            "hoddmimir-secret\0v1\0key_1\0pve_collector_token\0"
                .'00abcdef0123456789abcdef012345ff'
                ."\0token-secret",
            $context->additionalAuthenticatedData(1, 'key_1'),
        );

        foreach (['', str_repeat('a', 15), str_repeat('a', 17)] as $invalid) {
            try {
                SecretContext::forBinaryCredentialId($invalid, SecretPurpose::PveCollectorToken);
                self::fail('Invalid binary credential identifier was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The binary credential identifier is invalid.', $exception->getMessage());
            }
        }
    }

    public function testPurposesHaveStableDistinctKdfSubkeyIds(): void
    {
        self::assertSame(1, SecretPurpose::PveCollectorToken->kdfSubkeyId());
        self::assertSame(2, SecretPurpose::PbsCollectorToken->kdfSubkeyId());
        self::assertSame(3, SecretPurpose::PveBackupToken->kdfSubkeyId());
    }

    public function testItRejectsInvalidCredentialIdentifiers(): void
    {
        foreach (['', '-leading', "contains\0nul", 'contains space', str_repeat('x', 129)] as $credentialId) {
            try {
                SecretContext::forCredential($credentialId, SecretPurpose::PveCollectorToken);
                self::fail('Invalid credential identifier was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The credential identifier is invalid.', $exception->getMessage());
            }
        }
    }

    public function testItRejectsInvalidEnvelopeMetadata(): void
    {
        $context = SecretContext::forCredential('credential-1', SecretPurpose::PbsCollectorToken);

        try {
            $context->additionalAuthenticatedData(0, 'valid_key');
            self::fail('Invalid format version was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('The secret format version is invalid.', $exception->getMessage());
        }

        foreach (['', '_leading', 'Uppercase', 'contains:colon', str_repeat('x', 33)] as $keyId) {
            try {
                $context->additionalAuthenticatedData(1, $keyId);
                self::fail('Invalid key identifier was accepted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('The encryption key identifier is invalid.', $exception->getMessage());
            }
        }
    }
}
