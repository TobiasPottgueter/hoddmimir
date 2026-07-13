<?php

declare(strict_types=1);

namespace App\Tests\Integration\Database;

use App\Application\Security\Auth\CreateFirstAdmin;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Presentation\Http\Auth\RequestAuthenticator;
use App\Tests\Fakes\TestHttpRequestAuthenticator;

final class AuthApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $database;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $realAuthenticator = self::getContainer()->get(RequestAuthenticator::class);
        self::assertInstanceOf(RequestAuthenticator::class, $realAuthenticator);
        $testAuthenticator = self::getContainer()->get(TestHttpRequestAuthenticator::class);
        self::assertInstanceOf(TestHttpRequestAuthenticator::class, $testAuthenticator);
        $testAuthenticator->useDelegate($realAuthenticator);
        $database = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;
        $this->cleanup();
        $create = self::getContainer()->get(CreateFirstAdmin::class);
        self::assertInstanceOf(CreateFirstAdmin::class, $create);
        self::assertTrue($create->create('auth-smoke', 'Auth Smoke', 'smoke-password', false));
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->database->close();
        parent::tearDown();
    }

    public function testLoginSessionAndCsrfProtectedLogoutCookieContract(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['username' => 'auth-smoke', 'password' => 'wrong']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => ['code' => 'authentication_failed']], json_decode((string) $this->client->getResponse()->getContent(), true));

        $this->client->jsonRequest('POST', '/api/v1/auth/login', ['username' => 'auth-smoke', 'password' => 'smoke-password']);
        self::assertResponseIsSuccessful();
        $setCookie = (string) $this->client->getResponse()->headers->get('Set-Cookie');
        self::assertStringContainsString('hoddmimir_session=', $setCookie);
        self::assertStringContainsString('path=/', strtolower($setCookie));
        self::assertStringContainsString('httponly', strtolower($setCookie));
        self::assertStringContainsString('samesite=strict', strtolower($setCookie));
        self::assertStringNotContainsString('secure', strtolower($setCookie));
        $login = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($login);
        self::assertArrayNotHasKey('sessionToken', $login);
        self::assertIsString($login['csrfToken'] ?? null);

        $this->client->request('GET', '/api/v1/auth/session');
        self::assertResponseIsSuccessful();
        $session = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($session);
        $user = $session['user'] ?? null;
        self::assertIsArray($user);
        self::assertSame('auth-smoke', $user['username'] ?? null);
        self::assertArrayNotHasKey('sessionToken', $session);

        $this->client->request('POST', '/api/v1/auth/logout', server: ['HTTP_X_CSRF_TOKEN' => str_repeat('A', 43)]);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/v1/auth/session');
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/api/v1/auth/logout', server: ['HTTP_X_CSRF_TOKEN' => $login['csrfToken']]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/auth/session');
        self::assertResponseStatusCodeSame(401);
    }

    #[DataProvider('protectedApiPaths')]
    public function testEveryVersionedApiExceptLoginRejectsAnonymousRequests(string $method, string $path): void
    {
        $this->client->request($method, $path);
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => ['code' => 'authentication_required']], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    /** @return iterable<string, array{string, string}> */
    public static function protectedApiPaths(): iterable
    {
        yield 'session' => ['GET', '/api/v1/auth/session'];
        yield 'logout' => ['POST', '/api/v1/auth/logout'];
        yield 'inventory' => ['GET', '/api/v1/inventory/overview'];
        yield 'targets' => ['GET', '/api/v1/backup-targets'];
        yield 'policies' => ['GET', '/api/v1/policies'];
        yield 'shadow' => ['GET', '/api/v1/shadow/evaluations'];
    }

    public function testHealthRemainsPublic(): void
    {
        $this->client->request('GET', '/api/health');
        self::assertNotSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLoginRejectsDeclaredAndActualBodiesAboveTheBoundBeforeAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '4097',
        ], content: '{}');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/v1/auth/login', server: [
            'CONTENT_TYPE' => 'application/json',
        ], content: str_repeat('x', 4097));
        self::assertResponseStatusCodeSame(401);
        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM audit_events WHERE event_type = 'login_failed'"));
    }

    private function cleanup(): void
    {
        $id = $this->database->fetchOne("SELECT id FROM users WHERE username = 'auth-smoke'");
        if (!is_string($id)) {
            $this->database->executeStatement("DELETE FROM audit_events WHERE actor_user_id IS NULL AND event_type IN ('login_failed', 'session_revoked')");
            return;
        }
        $this->database->executeStatement('DELETE FROM audit_events WHERE actor_user_id = ? OR subject_id = ?', [$id, $id]);
        $this->database->executeStatement("DELETE FROM audit_events WHERE actor_user_id IS NULL AND event_type IN ('login_failed', 'session_revoked')");
        $this->database->executeStatement('DELETE FROM web_sessions WHERE user_id = ?', [$id]);
        $this->database->executeStatement("DELETE FROM login_attempts WHERE username = 'auth-smoke'");
        $this->database->executeStatement("DELETE FROM login_ip_attempts");
        $this->database->executeStatement("UPDATE login_global_throttle SET window_started_at=UTC_TIMESTAMP(6), failure_count=0, last_failed_at=UTC_TIMESTAMP(6), locked_until=NULL, updated_at=UTC_TIMESTAMP(6) WHERE singleton_id=1");
        $this->database->executeStatement('DELETE FROM user_roles WHERE user_id = ?', [$id]);
        $this->database->executeStatement('DELETE FROM users WHERE id = ?', [$id]);
    }
}
