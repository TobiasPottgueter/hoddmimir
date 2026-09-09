<?php

declare(strict_types=1);

namespace App\Application\Security;

interface ReferencedCredentialKeyIds
{
    /** @return list<string> */
    public function referencedKeyIds(): array;
}
