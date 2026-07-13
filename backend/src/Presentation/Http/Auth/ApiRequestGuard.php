<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth;

use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\AuthorizationDenied;
use App\Application\Security\Auth\PermissionAuthorizer;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Security\Permission;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ApiRequestGuard implements EventSubscriberInterface
{
    public const string SESSION_ATTRIBUTE = '_hoddmimir_authenticated_session';

    public function __construct(
        private HttpRequestAuthenticator $authenticator,
        private PermissionAuthorizer $authorizer,
        private OpaqueTokenCodec $tokens,
    ) {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1/')) {
            return;
        }
        if ('/api/v1/auth/login' === $request->getPathInfo() && Request::METHOD_POST === $request->getMethod()) {
            return;
        }

        try {
            $session = $this->authenticator->authenticate($request);
            $request->attributes->set(self::SESSION_ATTRIBUTE, $session);
            if (in_array($request->getMethod(), [Request::METHOD_GET, Request::METHOD_HEAD], true)) {
                $permission = '/api/v1/admin/health' === $request->getPathInfo()
                    ? Permission::InventoryRead
                    : (str_starts_with($request->getPathInfo(), '/api/v1/admin/audit-events')
                    ? Permission::AuditRead
                    : (str_starts_with($request->getPathInfo(), '/api/v1/admin/') ? Permission::SecurityManage
                        : (str_starts_with($request->getPathInfo(), '/api/v1/connections') ? Permission::BackupConfigurationManage : Permission::InventoryRead)));
                $this->authorizer->require($session->principal, $permission);
            } else {
                $this->requireCsrf($request, $session);
            }
        } catch (AuthenticationFailed) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'authentication_required']], Response::HTTP_UNAUTHORIZED));
        } catch (AuthorizationDenied) {
            $event->setResponse(new JsonResponse(['error' => ['code' => 'permission_denied']], Response::HTTP_FORBIDDEN));
        }
    }

    public static function session(Request $request): SessionResult
    {
        $session = $request->attributes->get(self::SESSION_ATTRIBUTE);
        if (!$session instanceof SessionResult) {
            throw new AuthenticationFailed();
        }

        return $session;
    }

    private function requireCsrf(Request $request, SessionResult $session): void
    {
        $header = $request->headers->get('X-CSRF-Token');
        if (!is_string($header)) {
            throw new AuthorizationDenied('A CSRF token is required.');
        }
        try {
            $submitted = $this->tokens->decode($header);
        } catch (AuthenticationFailed) {
            throw new AuthorizationDenied('The CSRF token is invalid.');
        }
        if (!hash_equals($session->csrfToken->bytes(), $submitted->bytes())) {
            throw new AuthorizationDenied('The CSRF token is invalid.');
        }
    }
}
