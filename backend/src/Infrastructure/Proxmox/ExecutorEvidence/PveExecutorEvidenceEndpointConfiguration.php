<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\PveExecutorIdentityValidator;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveApiUrlBuilder;
use App\Infrastructure\Proxmox\PveTlsConfiguration;
use InvalidArgumentException;
use LogicException;

final readonly class PveExecutorEvidenceEndpointConfiguration
{
    public string $backupOwnerIdentity;

    public function __construct(
        public string $connectionId,
        public string $endpointId,
        public int $connectionRevision,
        public int $backupCredentialRevision,
        public int $scanCredentialRevision,
        public int $majorVersion,
        public string $host,
        public int $port,
        public PveTlsConfiguration $tls,
        public string $backupTokenIdentity,
        private EncryptedSecret $backupSecret,
        private SecretContext $backupContext,
        private string $scanTokenIdentity,
        private EncryptedSecret $scanSecret,
        private SecretContext $scanContext,
    ) {
        $separator = \strrpos($backupTokenIdentity, '!');
        $this->backupOwnerIdentity = false === $separator ? '' : \substr($backupTokenIdentity, 0, $separator);
        if (16 !== \strlen($connectionId) || 16 !== \strlen($endpointId)
            || $connectionRevision < 1 || $backupCredentialRevision < 1 || $scanCredentialRevision < 1
            || !\in_array($majorVersion, [7, 8, 9], true)
            || !PveExecutorIdentityValidator::token($backupTokenIdentity)
            || !PveExecutorIdentityValidator::token($scanTokenIdentity)
            || $backupTokenIdentity === $scanTokenIdentity
            || SecretPurpose::PveBackupToken !== $backupContext->purpose()
            || SecretPurpose::PveCollectorToken !== $scanContext->purpose()) {
            throw new InvalidArgumentException('PVE executor evidence endpoint configuration is invalid.');
        }
        new PveApiUrlBuilder($host, $port);
    }

    public function backupAuthenticator(SecretCipher $cipher): PveExecutorEvidenceGetAuthenticator
    {
        return $this->authenticator($this->backupTokenIdentity, $this->backupSecret, $this->backupContext, $cipher);
    }

    public function scanAuthenticator(SecretCipher $cipher): PveExecutorEvidenceGetAuthenticator
    {
        return $this->authenticator($this->scanTokenIdentity, $this->scanSecret, $this->scanContext, $cipher);
    }

    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('PVE executor evidence configurations cannot be serialized.');
    }

    /** @param array<array-key, mixed> $data
     *  @return never
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('PVE executor evidence configurations cannot be unserialized.');
    }

    private function authenticator(
        string $tokenIdentity,
        EncryptedSecret $secret,
        SecretContext $context,
        SecretCipher $cipher,
    ): PveExecutorEvidenceGetAuthenticator {
        [$user, $token] = \explode('!', $tokenIdentity, 2);
        return new PveExecutorEvidenceTokenAuthenticator(
            PveApiTokenIdentity::fromUserAndTokenId($user, $token), $secret, $context, $cipher,
        );
    }
}
