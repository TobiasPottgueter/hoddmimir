<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingVerification
{
    /** @var list<OnboardingVerificationIssue> */ public array $issues;

    /** @param list<OnboardingVerificationIssue> $issues */
    public function __construct(
        public bool $tlsVerified,
        public bool $productSupported,
        public bool $scanPermissionsVerified,
        public ?bool $backupPermissionsVerified,
        public ?OnboardingProduct $detectedProduct,
        public ?string $detectedVersion,
        array $issues,
    ) {
        if ((null === $detectedProduct) !== (null === $detectedVersion)) {
            throw new InvalidArgumentException('The onboarding verification product evidence is inconsistent.');
        }
        $this->issues = $issues;
    }

    public function passed(): bool
    {
        if (!$this->tlsVerified || !$this->productSupported || !$this->scanPermissionsVerified
            || false === $this->backupPermissionsVerified) {
            return false;
        }
        foreach ($this->issues as $issue) {
            if (OnboardingIssueSeverity::Error === $issue->severity) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed> */
    public function toArray(bool $activated): array
    {
        return [
            'tls' => $this->tlsVerified ? 'tls_verified' : 'failed',
            'product' => $this->productSupported ? 'product_supported' : 'failed',
            'scanPermissions' => $this->scanPermissionsVerified ? 'scan_permissions_verified' : 'failed',
            'backupPermissions' => null === $this->backupPermissionsVerified
                ? null
                : ($this->backupPermissionsVerified ? 'backup_permissions_verified' : 'failed'),
            'activation' => $activated ? 'connection_activated' : 'not_activated',
            'inventory' => $activated ? 'first_automatic_scan_pending' : 'not_started',
            'detectedProduct' => $this->detectedProduct?->value,
            'detectedVersion' => $this->detectedVersion,
            'warnings' => array_values(array_map(
                static fn (OnboardingVerificationIssue $issue): array => $issue->toArray(),
                array_filter($this->issues, static fn (OnboardingVerificationIssue $issue): bool => OnboardingIssueSeverity::Warning === $issue->severity),
            )),
        ];
    }
}
