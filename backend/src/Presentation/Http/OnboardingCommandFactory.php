<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Configuration\Connection\Onboarding\OnboardingActivationCommand;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredential;
use App\Application\Configuration\Connection\Onboarding\OnboardingCredentialKind;
use App\Application\Configuration\Connection\Onboarding\OnboardingCustomCaValidator;
use App\Application\Configuration\Connection\Onboarding\OnboardingEndpoint;
use App\Application\Configuration\Connection\Onboarding\OnboardingMode;
use App\Application\Configuration\Connection\Onboarding\OnboardingProduct;
use App\Application\Configuration\Connection\Onboarding\OnboardingTlsMode;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class OnboardingCommandFactory
{
    public function __construct(
        private SecurityIdentifierGenerator $ids,
        private OnboardingCustomCaValidator $customCaValidator,
    )
    {
    }

    /** @throws JsonException */
    public function fromRequest(
        Request $request,
        OnboardingMode $mode,
        ?string $routeConnectionId = null,
        ?string $routeEndpointId = null,
    ): OnboardingActivationCommand
    {
        $validRoute = match ($mode) {
            OnboardingMode::Activate => null === $routeConnectionId && null === $routeEndpointId,
            OnboardingMode::Rotate, OnboardingMode::EndpointAdd => null !== $routeConnectionId && null === $routeEndpointId,
            OnboardingMode::EndpointUpdate => null !== $routeConnectionId && null !== $routeEndpointId,
        };
        if (!$validRoute) {
            throw new InvalidArgumentException('The onboarding route does not match its mode.');
        }
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new InvalidArgumentException('An idempotency key is required.');
        }
        /** @var mixed $body */
        $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body)) {
            throw new InvalidArgumentException('The onboarding request body must be an object.');
        }
        /** @var array<string, mixed> $body */
        $allowed = OnboardingMode::Rotate === $mode
            ? ['expectedRevision', 'endpointId', 'product', 'displayName', 'endpoint', 'credentials']
            : ['expectedRevision', 'product', 'displayName', 'endpoint', 'credentials'];
        $this->exact($body, $allowed);
        $revision = $body['expectedRevision'];
        $productValue = $body['product'];
        $displayName = $body['displayName'];
        if (!is_int($revision) || !is_string($productValue) || !is_string($displayName)) {
            throw new InvalidArgumentException('The onboarding request envelope is invalid.');
        }
        $product = OnboardingProduct::from($productValue);
        $endpoint = $this->endpoint($body['endpoint'] ?? null);
        $credentials = $this->credentials($body['credentials'] ?? null, $product);
        $endpointId = match ($mode) {
            OnboardingMode::Rotate => $this->uuid($this->string($body['endpointId'] ?? null)),
            OnboardingMode::EndpointUpdate => $this->uuid((string) $routeEndpointId),
            OnboardingMode::Activate, OnboardingMode::EndpointAdd => null,
        };
        $connectionId = null === $routeConnectionId ? $this->ids->generate() : $this->uuid($routeConnectionId);
        $correlation = $request->headers->get('X-Correlation-ID');

        return new OnboardingActivationCommand(
            $mode,
            $connectionId,
            $revision,
            $key,
            is_string($correlation) ? $this->uuid($correlation) : $this->ids->generate(),
            $product,
            $displayName,
            $endpoint,
            $credentials,
            $endpointId,
        );
    }

    private function endpoint(mixed $value): OnboardingEndpoint
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The onboarding endpoint is invalid.');
        }
        /** @var array<string, mixed> $value */
        $this->exact($value, ['host', 'port', 'tlsMode', 'customCaPem', 'sha256Fingerprint']);
        $host = $value['host'];
        $port = $value['port'];
        $tlsMode = $value['tlsMode'];
        $ca = $value['customCaPem'];
        $fingerprint = $value['sha256Fingerprint'];
        if (!is_string($host) || !is_int($port) || !is_string($tlsMode)
            || (null !== $ca && !is_string($ca)) || (null !== $fingerprint && !is_string($fingerprint))) {
            throw new InvalidArgumentException('The onboarding endpoint is invalid.');
        }
        if (OnboardingTlsMode::CustomCa === OnboardingTlsMode::from($tlsMode)
            && (null === $ca || !$this->customCaValidator->accepts($ca))) {
            throw new InvalidArgumentException('The onboarding TLS trust material is invalid.');
        }
        return new OnboardingEndpoint($host, $port, OnboardingTlsMode::from($tlsMode), $ca, $fingerprint);
    }

    /** @return list<OnboardingCredential> */
    private function credentials(mixed $value, OnboardingProduct $product): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('The onboarding credentials are invalid.');
        }
        /** @var array<string, mixed> $value */
        $this->exact($value, OnboardingProduct::Pve === $product ? ['scan', 'backup'] : ['scan']);
        $credentials = [$this->credential($value['scan'] ?? null, OnboardingCredentialKind::Scan)];
        if (OnboardingProduct::Pve === $product) {
            $credentials[] = $this->credential($value['backup'] ?? null, OnboardingCredentialKind::Backup);
        }
        return $credentials;
    }

    private function credential(mixed $value, OnboardingCredentialKind $kind): OnboardingCredential
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('An onboarding credential is invalid.');
        }
        /** @var array<string, mixed> $value */
        $this->exact($value, ['tokenId', 'tokenSecret']);
        return new OnboardingCredential(
            $kind,
            $this->string($value['tokenId'] ?? null),
            $this->string($value['tokenSecret'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string>         $keys
     */
    private function exact(array $value, array $keys): void
    {
        if (count($value) !== count($keys) || array_diff(array_keys($value), $keys) !== []) {
            throw new InvalidArgumentException('The onboarding request contains an unknown or missing field.');
        }
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('An onboarding string value is invalid.');
        }
        return $value;
    }

    private function uuid(string $value): string
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)) {
            throw new InvalidArgumentException('A canonical UUID is required.');
        }
        return hex2bin(str_replace('-', '', $value)) ?: throw new InvalidArgumentException('A canonical UUID is required.');
    }
}
