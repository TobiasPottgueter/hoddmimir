<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\ExecutorEvidence;

use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailure;
use App\Application\Backup\Execution\ExecutorEvidenceRefreshFailureCode;
use App\Application\Security\EncryptedSecret;
use App\Application\Security\SecretCipher;
use App\Application\Security\SecretContext;
use App\Infrastructure\Proxmox\PveApiTokenIdentity;
use Throwable;
use SensitiveParameter;

final readonly class PveExecutorEvidenceTokenAuthenticator implements PveExecutorEvidenceGetAuthenticator
{
    public function __construct(
        private PveApiTokenIdentity $identity,
        private EncryptedSecret $encryptedSecret,
        private SecretContext $secretContext,
        private SecretCipher $cipher,
    ) {
    }

    public function authorize(callable $request): mixed
    {
        try {
            $plaintext = $this->cipher->decrypt($this->encryptedSecret, $this->secretContext);
        } catch (Throwable) {
            throw ExecutorEvidenceRefreshFailure::for(ExecutorEvidenceRefreshFailureCode::CredentialUnavailable);
        }

        return $plaintext->consume(
            fn (#[SensitiveParameter] string $secret): mixed => $request($this->identity->authorizationPrefix().$secret),
        );
    }
}
