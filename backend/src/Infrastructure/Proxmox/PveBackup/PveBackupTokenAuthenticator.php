<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use App\Infrastructure\Proxmox\PveRequestAuthenticator;
use InvalidArgumentException;
use Throwable;

final readonly class PveBackupTokenAuthenticator implements PveRequestAuthenticator
{
    public function __construct(
        private PveApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
        private SecretCipher $secretCipher,
    ) {
        if (SecretPurpose::PveBackupToken !== $secretContext->purpose()) {
            throw new InvalidArgumentException('The PVE backup credential purpose is invalid.');
        }
    }

    public function authorize(callable $request): mixed
    {
        try {
            $plaintext = $this->secretCipher->decrypt($this->encryptedSecret, $this->secretContext);
        } catch (Throwable) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::CredentialUnavailable);
        }

        return $plaintext->consume(
            fn (string $secret): mixed => $request($this->identity->authorizationPrefix().$secret),
        );
    }
}
