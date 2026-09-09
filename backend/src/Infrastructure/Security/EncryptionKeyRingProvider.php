<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

interface EncryptionKeyRingProvider
{
    public function load(): EncryptionKeyRing;
}
