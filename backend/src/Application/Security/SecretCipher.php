<?php

declare(strict_types=1);

namespace App\Application\Security;

interface SecretCipher
{
    public function encrypt(PlaintextSecret $plaintext, SecretContext $context): EncryptedSecret;

    public function decrypt(EncryptedSecret $encrypted, SecretContext $context): PlaintextSecret;

    public function primaryKeyId(): string;
}
