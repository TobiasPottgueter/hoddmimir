<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth;

use App\Application\Security\Auth\SessionResult;
use Symfony\Component\HttpFoundation\Request;

interface HttpRequestAuthenticator
{
    public function authenticate(Request $request): SessionResult;
}
