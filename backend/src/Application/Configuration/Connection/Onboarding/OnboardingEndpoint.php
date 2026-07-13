<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingEndpoint
{
    public function __construct(
        public string $host,
        public int $port,
        public OnboardingTlsMode $tlsMode,
        public ?string $customCaPem,
        public ?string $sha256Fingerprint,
    ) {
        if (!OnboardingAsciiValidator::isHost($host)) {
            throw new InvalidArgumentException('The onboarding endpoint is invalid.');
        }
        if ($port < 1) {
            throw new InvalidArgumentException('The onboarding endpoint is invalid.');
        }
        if ($port > 65_535) {
            throw new InvalidArgumentException('The onboarding endpoint is invalid.');
        }
        if (OnboardingTlsMode::SystemCa === $tlsMode) {
            $this->requireSystemTrust($customCaPem, $sha256Fingerprint);
            return;
        }
        if (OnboardingTlsMode::CustomCa === $tlsMode) {
            $this->requireCustomCa($customCaPem, $sha256Fingerprint);
            return;
        }
        $this->requireFingerprint($customCaPem, $sha256Fingerprint);
    }

    private function requireSystemTrust(?string $customCaPem, ?string $fingerprint): void
    {
        if (null !== $customCaPem) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (null !== $fingerprint) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
    }

    private function requireCustomCa(?string $customCaPem, ?string $fingerprint): void
    {
        if (null === $customCaPem) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (strlen($customCaPem) > 262_144) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (!OnboardingAsciiValidator::contains($customCaPem, '-----BEGIN CERTIFICATE-----')) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (null !== $fingerprint) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
    }

    private function requireFingerprint(?string $customCaPem, ?string $fingerprint): void
    {
        if (null !== $customCaPem) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (null === $fingerprint) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        if (!OnboardingAsciiValidator::isLowerHex($fingerprint, 64)) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
    }
}
