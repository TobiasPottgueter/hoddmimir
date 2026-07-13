<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

use InvalidArgumentException;

final readonly class OnboardingActivationCommand
{
    /** @var list<OnboardingCredential> */
    public array $credentials;
    public string $payloadHash;

    /** @param list<OnboardingCredential> $credentials */
    public function __construct(
        public OnboardingMode $mode,
        public string $connectionId,
        public int $expectedRevision,
        public string $idempotencyKey,
        public string $correlationId,
        public OnboardingProduct $product,
        public string $displayName,
        public OnboardingEndpoint $endpoint,
        array $credentials,
        public ?string $endpointId = null,
    ) {
        if (16 !== strlen($connectionId)) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if (16 !== strlen($correlationId)) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if ($expectedRevision < 0) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if ('' === $displayName) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if (!OnboardingAsciiValidator::hasNormalizedBoundary($displayName)) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if (strlen($displayName) > 190) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if (!OnboardingAsciiValidator::isIdempotencyKey($idempotencyKey)) {
            throw new InvalidArgumentException('The onboarding command envelope is invalid.');
        }
        if (OnboardingMode::Activate === $mode || OnboardingMode::EndpointAdd === $mode) {
            if (null !== $endpointId) {
                throw new InvalidArgumentException('The onboarding command envelope is invalid.');
            }
        } else {
            if (null === $endpointId) {
                throw new InvalidArgumentException('The onboarding command envelope is invalid.');
            }
            if (16 !== strlen($endpointId)) {
                throw new InvalidArgumentException('The onboarding command envelope is invalid.');
            }
        }
        $byKind = [];
        foreach ($credentials as $credential) {
            if (isset($byKind[$credential->kind->value])) {
                throw new InvalidArgumentException('The onboarding credential set is invalid.');
            }
            $byKind[$credential->kind->value] = $credential;
        }
        $expectedKinds = OnboardingProduct::Pve === $product ? ['backup', 'scan'] : ['scan'];
        ksort($byKind);
        if (array_keys($byKind) !== $expectedKinds) {
            throw new InvalidArgumentException('The onboarding credential set is invalid.');
        }
        $this->credentials = array_values($byKind);
        $payload = [
            'mode' => $mode->value,
            'connectionId' => bin2hex($connectionId),
            'expectedRevision' => $expectedRevision,
            'product' => $product->value,
            'displayName' => $displayName,
            'endpoint' => [$endpoint->host, $endpoint->port, $endpoint->tlsMode->value, $endpoint->customCaPem, $endpoint->sha256Fingerprint],
            'endpointId' => null === $endpointId ? null : bin2hex($endpointId),
            'tokenIds' => array_map(static fn (OnboardingCredential $credential): string => $credential->tokenId, $this->credentials),
        ];
        $this->payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), true);
    }

    public function credential(OnboardingCredentialKind $kind): OnboardingCredential
    {
        foreach ($this->credentials as $credential) {
            if ($credential->kind === $kind) {
                return $credential;
            }
        }
        throw new InvalidArgumentException('The requested onboarding credential is unavailable.');
    }
}
