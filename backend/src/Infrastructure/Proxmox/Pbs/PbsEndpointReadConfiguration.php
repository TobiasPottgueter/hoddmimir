<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use LogicException;

/** Secret-bearing infrastructure DTO that must never leave the connector factory. */
final readonly class PbsEndpointReadConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public PbsTlsConfiguration $tls,
        private PbsApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
    ) {
        new PbsApiUrlBuilder($host, $port);
    }

    public function authenticator(SecretCipher $cipher): PbsRequestAuthenticator
    {
        return new PbsTokenAuthenticator(
            $this->identity,
            $this->encryptedSecret,
            $this->secretContext,
            $cipher,
        );
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('PBS endpoint read configurations cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data
     *  @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('PBS endpoint read configurations cannot be unserialized.');
    }
}
