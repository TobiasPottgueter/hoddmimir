<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth;

use App\Application\Security\Auth\AuthenticateSession;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\SessionResult;
use Symfony\Component\HttpFoundation\Request;

final readonly class RequestAuthenticator implements HttpRequestAuthenticator
{
    public const string COOKIE_NAME = 'hoddmimir_session';

    public function __construct(private AuthenticateSession $sessions, private OpaqueTokenCodec $tokens)
    {
    }

    public function authenticate(Request $request): SessionResult
    {
        $cookie = $request->cookies->get(self::COOKIE_NAME);
        if (!is_string($cookie)) {
            throw new AuthenticationFailed();
        }

        return $this->sessions->authenticate($this->tokens->decode($cookie));
    }
}
