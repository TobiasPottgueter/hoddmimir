<?php

declare(strict_types=1);

namespace App\Infrastructure\Readiness;

use App\Application\Readiness\ReadinessCheck;
use App\Application\Readiness\ReadinessCheckResult;
use App\Application\Security\ReferencedCredentialKeyIds;
use App\Infrastructure\Security\EncryptionConfigurationException;
use App\Infrastructure\Security\EncryptionConfigurationFailure;
use App\Infrastructure\Security\EncryptionKeyRingProvider;
use Throwable;

final readonly class EncryptionKeyRingReadinessCheck implements ReadinessCheck
{
    private const NAME = 'encryption_keyring';

    public function __construct(
        private EncryptionKeyRingProvider $keyRingLoader,
        private ReferencedCredentialKeyIds $referencedCredentialKeyIds,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function check(): ReadinessCheckResult
    {
        try {
            $keyRing = $this->keyRingLoader->load();
        } catch (EncryptionConfigurationException $exception) {
            return ReadinessCheckResult::unavailable(self::NAME, $this->reasonFor($exception->failure));
        } catch (Throwable) {
            return ReadinessCheckResult::unavailable(self::NAME, 'invalid');
        }

        try {
            $referencedKeyIds = $this->referencedCredentialKeyIds->referencedKeyIds();
        } catch (Throwable) {
            return ReadinessCheckResult::unavailable(self::NAME, 'usage_unavailable');
        }

        foreach ($referencedKeyIds as $keyId) {
            if (!$keyRing->hasKey($keyId)) {
                return ReadinessCheckResult::unavailable(self::NAME, 'missing_referenced_key');
            }
        }

        return ReadinessCheckResult::ready(self::NAME);
    }

    private function reasonFor(EncryptionConfigurationFailure $failure): string
    {
        if ($failure === EncryptionConfigurationFailure::SecretFileUnavailable) {
            return 'missing';
        }

        if ($failure === EncryptionConfigurationFailure::InvalidRevision) {
            return 'revision_mismatch';
        }

        return 'invalid';
    }
}
