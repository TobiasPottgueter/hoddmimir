<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Administration\ReadModel\AdministrationAuditEvent;
use App\Application\Administration\ReadModel\AdministrationPage;
use App\Application\Administration\ReadModel\AdministrationReadModel;
use App\Application\Administration\ReadModel\AdministrationRole;
use App\Application\Administration\ReadModel\AdministrationUser;
use App\Application\Administration\ReadModel\AuditListQuery;
use App\Application\Administration\ReadModel\UserListQuery;
use App\Application\Administration\SecurityCommand;
use App\Application\Administration\SecurityCommandRepository;
use App\Application\Administration\SecurityCommandResult;
use App\Application\Administration\SecurityCommandStatus;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Security\Audit\AuditEventType;
use App\Application\Security\Audit\AuditOutcome;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SessionResult;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Infrastructure\Persistence\MariaDb\DbalSecurityAdministration;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class AdministrationApiTest extends WebTestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';
    private KernelBrowser $client;
    private AdministrationStoreFake $store;
    private AdministrationAuthenticator $auth;
    private string $csrf;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->store = new AdministrationStoreFake();
        self::getContainer()->set(DbalSecurityAdministration::class, $this->store);
        $this->auth = new AdministrationAuthenticator([Permission::SecurityManage, Permission::AuditRead]);
        self::getContainer()->set(HttpRequestAuthenticator::class, $this->auth);
        $this->csrf = rtrim(strtr(base64_encode(str_repeat("\x03", 32)), '+/', '-_'), '=');
    }

    public function testClosedReadsUseDedicatedPermissionsAndNeverExposePasswords(): void
    {
        $this->client->request('GET', '/api/v1/admin/users?enabled=true&limit=1&search=admin');
        self::assertResponseIsSuccessful();
        $users = $this->json();
        $items = $users['items'] ?? null;
        self::assertIsArray($items);
        $first = $items[0] ?? null;
        self::assertIsArray($first);
        self::assertArrayNotHasKey('password', $first);
        self::assertSame('admin', $this->store->userQuery?->search);

        $this->client->request('GET', '/api/v1/admin/roles?limit=1');
        self::assertResponseIsSuccessful();
        $this->client->request('GET', '/api/v1/admin/audit-events?eventType=user_created&outcome=succeeded&limit=1');
        self::assertResponseIsSuccessful();
        self::assertSame('user_created', $this->store->auditQuery?->eventType?->value);
        $this->client->request('GET', '/api/v1/admin/audit-events/'.self::UUID);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/admin/users?unknown=1');
        self::assertResponseStatusCodeSame(400);
        $this->auth->permissions = [Permission::SecurityManage];
        $this->client->request('GET', '/api/v1/admin/audit-events');
        self::assertResponseStatusCodeSame(403);
        $this->auth->permissions = [Permission::AuditRead];
        $this->client->request('GET', '/api/v1/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function testCommandsEnforceCsrfIdempotencyRevisionAndSafeFailures(): void
    {
        $body = ['expectedRevision' => 0, 'username' => 'new.admin', 'displayName' => 'New Admin', 'password' => 'very-secret-1', 'roles' => ['admin']];
        $this->client->jsonRequest('POST', '/api/v1/admin/users', $body);
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/v1/admin/users', $body, false);
        self::assertResponseStatusCodeSame(400);
        $invalid = $body; $invalid['unknown'] = 'password-leak';
        $this->request('POST', '/api/v1/admin/users', $invalid);
        self::assertResponseStatusCodeSame(400);
        self::assertStringNotContainsString('password-leak', (string) $this->client->getResponse()->getContent());

        $this->request('POST', '/api/v1/admin/users', $body);
        self::assertResponseIsSuccessful();
        self::assertSame(['status' => 'applied', 'revision' => 1], $this->json());
        self::assertNotNull($this->store->lastCommand?->passwordHash);

        $this->store->result = new SecurityCommandResult(SecurityCommandStatus::Conflict, 7);
        $this->request('PUT', '/api/v1/admin/users/'.self::UUID, ['expectedRevision' => 1, 'displayName' => 'Changed']);
        self::assertResponseStatusCodeSame(409);
        $this->store->result = SecurityCommandResult::blocked('last_active_admin');
        $this->request('POST', '/api/v1/admin/users/'.self::UUID.'/disable', ['expectedRevision' => 1]);
        self::assertResponseStatusCodeSame(422);
        $error = $this->json()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(['last_active_admin'], $error['blockers'] ?? null);
        $this->store->fail = true;
        $this->request('PUT', '/api/v1/admin/users/'.self::UUID.'/roles', ['expectedRevision' => 1, 'roles' => ['viewer']]);
        self::assertResponseStatusCodeSame(503);
        self::assertStringNotContainsString('database secret', (string) $this->client->getResponse()->getContent());
    }

    public function testPermissionDenialIsRecordedAndEveryMutationRouteIsRoutable(): void
    {
        $this->auth->permissions = [];
        $this->request('POST', '/api/v1/admin/users/'.self::UUID.'/disable', ['expectedRevision' => 1]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->store->records);
        $this->auth->permissions = [Permission::SecurityManage];
        foreach ([
            ['POST', '/api/v1/admin/users', ['expectedRevision' => 0, 'username' => 'new.user', 'displayName' => 'New', 'password' => 'very-secret-1', 'roles' => ['viewer']]],
            ['PUT', '/api/v1/admin/users/'.self::UUID, ['expectedRevision' => 1, 'displayName' => 'Changed']],
            ['POST', '/api/v1/admin/users/'.self::UUID.'/disable', ['expectedRevision' => 1]],
            ['PUT', '/api/v1/admin/users/'.self::UUID.'/roles', ['expectedRevision' => 1, 'roles' => ['admin']]],
        ] as [$method, $path, $body]) {
            $this->request($method, $path, $body);
            self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
            self::assertNotSame(405, $this->client->getResponse()->getStatusCode());
        }
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, string $path, array $body, bool $idempotency = true): void
    {
        $server = ['HTTP_X_CSRF_TOKEN' => $this->csrf];
        if ($idempotency) $server['HTTP_IDEMPOTENCY_KEY'] = 'request-1';
        $this->client->jsonRequest($method, $path, $body, $server);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $value = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($value);
        /** @var array<string, mixed> $value */
        return $value;
    }
}

final class AdministrationStoreFake implements SecurityCommandRepository, AdministrationReadModel
{
    public SecurityCommandResult $result;
    public bool $fail = false;
    public int $records = 0;
    public ?SecurityCommand $lastCommand = null;
    public ?UserListQuery $userQuery = null;
    public ?AuditListQuery $auditQuery = null;
    public function __construct() { $this->result = new SecurityCommandResult(SecurityCommandStatus::Applied, 1); }
    public function execute(SecurityCommand $command, AuthenticatedPrincipal $principal): SecurityCommandResult { if ($this->fail) throw new RuntimeException('database secret'); $this->lastCommand = $command; return $this->result; }
    public function record(SecurityCommand $command, AuthenticatedPrincipal $principal, SecurityCommandResult $result): SecurityCommandResult { ++$this->records; return $result; }
    public function users(UserListQuery $query): AdministrationPage { $this->userQuery = $query; return new AdministrationPage($query->page, [new AdministrationUser(self::UUID, 'admin', 'Admin', true, 1, ['admin'], '2026-07-12T10:00:00.000000Z', '2026-07-12T10:00:00.000000Z', null, null)], null); }
    public function roles(PageRequest $page): AdministrationPage { return new AdministrationPage($page, [new AdministrationRole(self::UUID, 'admin', 'Administrator', ['security.manage'])], null); }
    public function audit(AuditListQuery $query): AdministrationPage { $this->auditQuery = $query; return new AdministrationPage($query->page, [$this->event()], null); }
    public function auditEvent(string $id): ?AdministrationAuditEvent { return self::UUID === $id ? $this->event() : null; }
    private function event(): AdministrationAuditEvent { return new AdministrationAuditEvent(self::UUID, '2026-07-12T10:00:00.000000Z', null, null, AuditEventType::UserCreated, AuditOutcome::Succeeded, 'user', self::UUID, null, self::UUID); }
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';
}

final class AdministrationAuthenticator implements HttpRequestAuthenticator
{
    /** @param list<Permission> $permissions */ public function __construct(public array $permissions) {}
    public function authenticate(Request $request): SessionResult
    {
        $now = new DateTimeImmutable('2026-07-12T10:00:00Z');
        return new SessionResult(str_repeat('s', 16), new AuthenticatedPrincipal(new UserId(str_repeat('u', 16)), new NormalizedUsername('admin'), $this->permissions), new OpaqueToken(str_repeat("\x03", 32)), new SessionWindow($now, $now, $now->modify('+30 minutes'), $now->modify('+12 hours')));
    }
}
