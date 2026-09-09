<?php

declare(strict_types=1);

namespace App\Presentation\Http\Controller;

use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\LocalLogin;
use App\Application\Security\Auth\LogoutSession;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Application\Security\Auth\SessionResult;
use App\Presentation\Http\Auth\OpaqueTokenCodec;
use App\Presentation\Http\Auth\RequestAuthenticator;
use App\Presentation\Http\Auth\ApiRequestGuard;
use JsonException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

final readonly class AuthController
{
    private const int MAX_LOGIN_BODY_BYTES = 4096;

    private bool $secureCookie;

    public function __construct(
        private LocalLogin $login,
        private LogoutSession $logout,
        private OpaqueTokenCodec $tokens,
        private SecurityIdentifierGenerator $ids,
        string $environment,
    ) {
        $this->secureCookie = !in_array($environment, ['dev', 'test'], true);
    }

    #[Route('/api/v1/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        try {
            $payload = $this->json($request);
            if (2 !== count($payload) || !array_key_exists('username', $payload) || !array_key_exists('password', $payload)
                || !is_string($payload['username']) || !is_string($payload['password'])) {
                throw new AuthenticationFailed();
            }
            $ip = inet_pton((string) $request->getClientIp());
            if (false === $ip) {
                throw new AuthenticationFailed();
            }
            $result = $this->login->authenticate($payload['username'], $payload['password'], $ip, $this->ids->generate());
            $response = new JsonResponse($this->sessionPayload(new SessionResult('', $result->principal, $result->csrfToken, $result->window)));
            $response->headers->setCookie(Cookie::create(
                RequestAuthenticator::COOKIE_NAME,
                $this->tokens->encode($result->sessionToken),
                $result->window->absoluteExpiresAt,
                '/',
                null,
                $this->secureCookie,
                true,
                false,
                Cookie::SAMESITE_STRICT,
            ));

            return $response;
        } catch (AuthenticationFailed|JsonException|\InvalidArgumentException) {
            return $this->unauthorized();
        }
    }

    #[Route('/api/v1/auth/session', name: 'api_v1_auth_session', methods: ['GET'])]
    public function session(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->sessionPayload(ApiRequestGuard::session($request)));
        } catch (AuthenticationFailed) {
            return $this->unauthorized();
        }
    }

    #[Route('/api/v1/auth/logout', name: 'api_v1_auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        try {
            $cookie = $request->cookies->get(RequestAuthenticator::COOKIE_NAME);
            $csrf = $request->headers->get('X-CSRF-Token');
            if (!is_string($cookie) || !is_string($csrf)) {
                throw new AuthenticationFailed();
            }
            $this->logout->logout($this->tokens->decode($cookie), $this->tokens->decode($csrf), $this->ids->generate());
            $response = new JsonResponse(null, Response::HTTP_NO_CONTENT);
            $response->headers->clearCookie(RequestAuthenticator::COOKIE_NAME, '/', null, $this->secureCookie, true, Cookie::SAMESITE_STRICT);

            return $response;
        } catch (AuthenticationFailed) {
            return $this->unauthorized();
        }
    }

    /** @return array<array-key, mixed> */
    private function json(Request $request): array
    {
        $contentLength = $request->headers->get('Content-Length');
        if (is_string($contentLength)
            && (1 !== preg_match('/\A[0-9]+\z/D', $contentLength)
                || strlen($contentLength) > 10
                || (int) $contentLength > self::MAX_LOGIN_BODY_BYTES)) {
            throw new AuthenticationFailed();
        }
        $content = $request->getContent();
        if (strlen($content) > self::MAX_LOGIN_BODY_BYTES) {
            throw new AuthenticationFailed();
        }

        /** @var mixed $decoded */
        $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new AuthenticationFailed();
        }

        return $decoded;
    }

    /** @return array{user: array{id: string, username: string, permissions: list<string>}, csrfToken: string, idleExpiresAt: string, absoluteExpiresAt: string} */
    private function sessionPayload(SessionResult $session): array
    {
        return [
            'user' => [
                'id' => $this->uuid($session->principal->userId->binary()),
                'username' => $session->principal->username->value,
                'permissions' => array_map(static fn ($permission): string => $permission->value, $session->principal->permissions),
            ],
            'csrfToken' => $this->tokens->encode($session->csrfToken),
            'idleExpiresAt' => $session->window->idleExpiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'absoluteExpiresAt' => $session->window->absoluteExpiresAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => 'authentication_failed']], Response::HTTP_UNAUTHORIZED);
    }

    private function uuid(string $binary): string
    {
        $hex = bin2hex($binary);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
