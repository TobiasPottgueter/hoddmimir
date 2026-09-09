<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Application\Security\Auth\CsrfTokenDeriver;
use App\Application\Security\Auth\OpaqueToken;
use InvalidArgumentException;
use SensitiveParameter;

final readonly class HmacCsrfTokenDeriver implements CsrfTokenDeriver
{
    private string $csrfKey;

    public function __construct(#[SensitiveParameter] string $applicationSecret)
    {
        if (strlen($applicationSecret) < 32) {
            throw new InvalidArgumentException('APP_SECRET must contain at least 32 bytes.');
        }
        $this->csrfKey = hash_hmac('sha256', 'hoddmimir.csrf.key.v1', $applicationSecret, true);
    }

    public function derive(OpaqueToken $sessionToken): OpaqueToken
    {
        return new OpaqueToken(hash_hmac(
            'sha256',
            "hoddmimir.csrf.token.v1\0".$sessionToken->bytes(),
            $this->csrfKey,
            true,
        ));
    }
}
