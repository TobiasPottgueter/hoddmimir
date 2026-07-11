<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Infrastructure\Validation\AsciiPatternValidator;
use InvalidArgumentException;

final readonly class PbsApiTokenIdentity
{
    private function __construct(private string $value)
    {
    }

    public static function fromParts(string $username, string $realm, string $tokenName): self
    {
        $usernameValid = AsciiPatternValidator::matches('/\A[^\s:\/[:cntrl:]]+\z/D', $username);
        $realmValid = AsciiPatternValidator::matches('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $realm);
        $tokenValid = AsciiPatternValidator::matches('/\A[A-Za-z0-9_][A-Za-z0-9._-]*\z/D', $tokenName);
        $value = $username.'@'.$realm.'!'.$tokenName;
        if (!$usernameValid || !$realmValid || !$tokenValid || strlen($value) > 64) {
            throw new InvalidArgumentException('The PBS API token identity is invalid.');
        }
        return new self($value);
    }

    public function authorizationPrefix(): string
    {
        return 'PBSAPIToken '.$this->value.':';
    }
}
