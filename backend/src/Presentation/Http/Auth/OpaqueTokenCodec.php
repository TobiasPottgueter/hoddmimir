<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth;

use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\OpaqueToken;

final readonly class OpaqueTokenCodec
{
    public function encode(OpaqueToken $token): string
    {
        return rtrim(strtr(base64_encode($token->bytes()), '+/', '-_'), '=');
    }

    public function decode(string $encoded): OpaqueToken
    {
        if (43 !== strlen($encoded) || 1 !== preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $encoded)) {
            throw new AuthenticationFailed();
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/').'=', true);
        if (false === $decoded) {
            throw new AuthenticationFailed();
        }

        return new OpaqueToken($decoded);
    }
}
