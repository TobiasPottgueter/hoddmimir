<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use RuntimeException;

final class EncryptionConfigurationException extends RuntimeException
{
    private function __construct(public readonly EncryptionConfigurationFailure $failure)
    {
        parent::__construct('Encryption key configuration is invalid.');
    }

    public static function for(EncryptionConfigurationFailure $failure): self
    {
        return new self($failure);
    }
}
