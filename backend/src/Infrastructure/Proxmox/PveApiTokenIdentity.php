<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Infrastructure\Validation\AsciiPatternValidator;
use InvalidArgumentException;

final readonly class PveApiTokenIdentity
{
    private function __construct(private string $value)
    {
    }

    public static function fromUserAndTokenId(string $user, string $tokenId): self
    {
        $userMatches = strlen($user) <= 64
            && AsciiPatternValidator::matches('/\A[^\s:\/\x00-\x1F\x7F]+@[A-Za-z][A-Za-z0-9._-]+\z/uD', $user);
        if (!$userMatches) {
            throw new InvalidArgumentException('The PVE API token identity is invalid.');
        }

        $tokenMatches = AsciiPatternValidator::matches('/\A[A-Za-z][A-Za-z0-9._-]{1,63}\z/D', $tokenId);
        if (!$tokenMatches) {
            throw new InvalidArgumentException('The PVE API token identity is invalid.');
        }

        return new self($user.'!'.$tokenId);
    }

    public function authorizationPrefix(): string
    {
        return 'PVEAPIToken='.$this->value.'=';
    }
}
