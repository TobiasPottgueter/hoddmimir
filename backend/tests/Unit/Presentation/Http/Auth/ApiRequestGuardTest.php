<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http\Auth;

use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Presentation\Http\Auth\ApiRequestGuard;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use App\Presentation\Http\Auth\OpaqueTokenCodec;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiRequestGuardTest extends TestCase
{
    public function testSessionGetAuthenticatesExactlyOnceAndPublishesTypedAttribute(): void
    {
        $session = $this->session();
        $auth = $this->createMock(HttpRequestAuthenticator::class);
        $auth->expects(self::once())->method('authenticate')->willReturn($session);
        $request = Request::create('/api/v1/auth/session', 'GET');
        $event = $this->event($request);
        (new ApiRequestGuard($auth, new PermissionAuthorizer(), new OpaqueTokenCodec()))->onKernelRequest($event);
        self::assertFalse($event->hasResponse());
        self::assertSame($session, ApiRequestGuard::session($request));
    }

    public function testAnonymousAndCsrfFailuresAreClosedWithoutHeaderIdentity(): void
    {
        $auth = $this->createStub(HttpRequestAuthenticator::class);
        $auth->method('authenticate')->willThrowException(new AuthenticationFailed());
        $request = Request::create('/api/v1/inventory/overview', 'GET', server: ['HTTP_X_USER' => 'admin']);
        $event = $this->event($request);
        (new ApiRequestGuard($auth, new PermissionAuthorizer(), new OpaqueTokenCodec()))->onKernelRequest($event);
        self::assertSame(401, $event->getResponse()?->getStatusCode());

        $auth = $this->createStub(HttpRequestAuthenticator::class);
        $auth->method('authenticate')->willReturn($this->session());
        $event = $this->event(Request::create('/api/v1/auth/logout', 'POST'));
        (new ApiRequestGuard($auth, new PermissionAuthorizer(), new OpaqueTokenCodec()))->onKernelRequest($event);
        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    public function testAdministrationHealthUsesInventoryReadWithoutOpeningSecurityAdministration(): void
    {
        $auth = $this->createStub(HttpRequestAuthenticator::class);
        $auth->method('authenticate')->willReturn($this->session());
        $guard = new ApiRequestGuard($auth, new PermissionAuthorizer(), new OpaqueTokenCodec());

        $health = $this->event(Request::create('/api/v1/admin/health', 'GET'));
        $guard->onKernelRequest($health);
        self::assertFalse($health->hasResponse());

        $users = $this->event(Request::create('/api/v1/admin/users', 'GET'));
        $guard->onKernelRequest($users);
        self::assertSame(403, $users->getResponse()?->getStatusCode());
    }

    private function session(): SessionResult
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');
        return new SessionResult(
            str_repeat('s', 16),
            new AuthenticatedPrincipal(new UserId(str_repeat('u', 16)), new NormalizedUsername('viewer'), [Permission::InventoryRead]),
            new OpaqueToken(str_repeat('c', 32)),
            new SessionWindow($now, $now, $now->modify('+30 minutes'), $now->modify('+12 hours')),
        );
    }

    private function event(Request $request): RequestEvent
    {
        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }
}
