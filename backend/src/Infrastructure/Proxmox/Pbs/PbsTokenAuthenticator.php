<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\AsciiPatternValidator;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Application\Security\SecretPurpose;
use InvalidArgumentException;
use Throwable;

final readonly class PbsTokenAuthenticator implements PbsRequestAuthenticator
{
    public function __construct(
        private PbsApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
        private SecretCipher $secretCipher,
    ) {
        if (SecretPurpose::PbsCollectorToken !== $secretContext->purpose()) {
            throw new InvalidArgumentException('The PBS collector credential purpose is invalid.');
        }
    }

    public function authorize(callable $request): mixed
    {
        try {
            $plaintext = $this->secretCipher->decrypt($this->encryptedSecret, $this->secretContext);
        } catch (Throwable) {
            throw PbsReadFailure::for(PbsReadFailureCode::CredentialUnavailable);
        }

        return $plaintext->consume(function (string $secret) use ($request): mixed {
            if (!AsciiPatternValidator::matches('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $secret)) {
                throw PbsReadFailure::for(PbsReadFailureCode::CredentialUnavailable);
            }
            return $request($this->identity->authorizationPrefix().$secret);
        });
    }
}
