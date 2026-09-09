<?php

declare(strict_types=1);

namespace App\Tests\Fakes;

use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

final class TestHttpRequestAuthenticator implements HttpRequestAuthenticator
{
    private ?HttpRequestAuthenticator $delegate = null;
    /** @var list<Permission> */ private array $permissions = [Permission::InventoryRead];

    public function useDelegate(HttpRequestAuthenticator $delegate): void
    {
        $this->delegate = $delegate;
    }

    /** @param list<Permission> $permissions */
    public function usePermissions(array $permissions): void
    {
        $this->delegate = null;
        $this->permissions = $permissions;
    }

    public function authenticate(Request $request): SessionResult
    {
        if (null !== $this->delegate) {
            return $this->delegate->authenticate($request);
        }
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');

        return new SessionResult(
            str_repeat("\x01", 16),
            new AuthenticatedPrincipal(
                new UserId(str_repeat("\x02", 16)),
                new NormalizedUsername('qa-viewer'),
                $this->permissions,
            ),
            new OpaqueToken(str_repeat("\x03", 32)),
            new SessionWindow($now, $now, $now->modify('+30 minutes'), $now->modify('+12 hours')),
        );
    }
}
