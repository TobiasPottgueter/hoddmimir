<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use InvalidArgumentException;
use Throwable;

final readonly class PveTokenAuthenticator implements PveRequestAuthenticator
{
    public function __construct(
        private PveApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
        private SecretCipher $secretCipher,
    ) {
        if (SecretPurpose::PveCollectorToken !== $secretContext->purpose()) {
            throw new InvalidArgumentException('The PVE collector credential purpose is invalid.');
        }
    }

    /**
     * @template TResult
     * @param callable(string): TResult $request
     * @return TResult
     */
    public function authorize(callable $request): mixed
    {
        try {
            $plaintext = $this->secretCipher->decrypt($this->encryptedSecret, $this->secretContext);
        } catch (Throwable) {
            throw PveReadFailure::for(PveReadFailureCode::CredentialUnavailable);
        }

        return $plaintext->consume(
            fn (string $secret): mixed => $request($this->identity->authorizationPrefix().$secret),
        );
    }
}
