<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use RuntimeException;

final class SecretCipherException extends RuntimeException
{
    private function __construct(public readonly SecretCipherFailure $failure)
    {
        parent::__construct(
            $failure === SecretCipherFailure::EncryptionFailed
                ? 'Secret encryption failed.'
                : 'Encrypted secret is unavailable.',
        );
    }

    public static function for(SecretCipherFailure $failure): self
    {
        return new self($failure);
    }
}
