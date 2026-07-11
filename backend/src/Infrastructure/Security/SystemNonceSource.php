<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final readonly class SystemNonceSource implements NonceSource
{
    public function nextNonce(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    }
}
