<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use LogicException;

final readonly class PveBackupEndpointConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public PveTlsConfiguration $tls,
        private PveApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
    ) {
        new PveApiUrlBuilder($host, $port);
    }

    public function authenticator(SecretCipher $cipher): PveRequestAuthenticator
    {
        return new PveBackupTokenAuthenticator(
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
        throw new LogicException('PVE backup endpoint configurations cannot be serialized.');
    }
}
