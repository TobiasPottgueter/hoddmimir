<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use LogicException;

/**
 * Secret-bearing infrastructure DTO. It may be consumed only to build the
 * request authenticator and must never cross into Application or Domain.
 */
final readonly class PveEndpointReadConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public PveTlsConfiguration $tls,
        private PveApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
    ) {
        // Validate the authority at the point where database data becomes a
        // runtime configuration, without making a network request.
        new PveApiUrlBuilder($host, $port);
    }

    public function authenticator(SecretCipher $cipher): PveRequestAuthenticator
    {
        return new PveTokenAuthenticator(
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
        throw new LogicException('PVE endpoint read configurations cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data
     *  @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('PVE endpoint read configurations cannot be unserialized.');
    }
}
