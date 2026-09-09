<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceItem;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidencePage;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceQuery;
use App\Application\Backup\Execution\ReadModel\ExecutorPermissionEvidenceReadModel;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Security\Auth\AuthenticatedPrincipal;
use App\Application\Security\Auth\AuthenticationFailed;
use App\Application\Security\Auth\OpaqueToken;
use App\Application\Security\Auth\SessionResult;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Domain\Security\NormalizedUsername;
use App\Domain\Security\Permission;
use App\Domain\Security\SessionWindow;
use App\Domain\Security\UserId;
use App\Domain\Shared\UInt64Decimal;
use App\Infrastructure\Persistence\MariaDb\DbalExecutorPermissionEvidenceReadModel;
use App\Presentation\Http\Auth\HttpRequestAuthenticator;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ExecutorPermissionEvidenceApiTest extends WebTestCase
{
    public const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    public const string OTHER = '11112233-4455-6677-8899-aabbccddeeff';

    private KernelBrowser $client;
    private ExecutorEvidenceAuthenticator $authenticator;
    private ExecutorPermissionEvidenceReadModelFake $readModel;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->authenticator = new ExecutorEvidenceAuthenticator();
        $this->readModel = new ExecutorPermissionEvidenceReadModelFake();
        self::getContainer()->set(HttpRequestAuthenticator::class, $this->authenticator);
        self::getContainer()->set(DbalExecutorPermissionEvidenceReadModel::class, $this->readModel);
    }

    public function testReadRequiresSessionAndInventoryPermission(): void
    {
        $this->authenticator->authenticated = false;
        $this->client->request('GET', '/api/v1/executor-permission-evidence');
        self::assertResponseStatusCodeSame(401);

        $this->authenticator->authenticated = true;
        $this->authenticator->permissions = [];
        $this->client->request('GET', '/api/v1/executor-permission-evidence');
        self::assertResponseStatusCodeSame(403);

        $this->authenticator->permissions = [Permission::InventoryRead];
        $this->client->request('GET', '/api/v1/executor-permission-evidence?limit=1');
        self::assertResponseIsSuccessful();
    }

    public function testGetReturnsClosedEvidenceAndBindsEveryFilterToTheCursor(): void
    {
        $filters = 'connectionId='.self::ID.'&clusterId='.self::ID.'&targetId='.self::ID
            .'&nodeId='.self::ID.'&guestId='.self::ID;
        $this->client->request('GET', '/api/v1/executor-permission-evidence?limit=1&'.$filters);
        self::assertResponseIsSuccessful();
        $payload = $this->json();
        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        self::assertSame([
            'id', 'connectionId', 'clusterId', 'targetId', 'nodeId', 'storageId', 'guestId',
            'evidenceSetRevision', 'endpointId', 'connectionRevision', 'backupCredentialRevision',
            'scanCredentialRevision', 'observedAt', 'freshness', 'vmBackupAuthorized',
            'datastoreAllocateAuthorized', 'authorized', 'missingPermissions',
        ], array_keys($item));
        self::assertSame('9', $item['evidenceSetRevision']);
        self::assertSame(['Datastore.AllocateSpace'], $item['missingPermissions']);
        self::assertFalse($item['authorized']);

        $query = $this->readModel->query;
        self::assertNotNull($query);
        foreach ([$query->connectionId, $query->clusterId, $query->targetId, $query->nodeId, $query->guestId] as $filter) {
            self::assertSame(self::ID, $filter?->value);
        }
        $page = $payload['page'] ?? null;
        self::assertIsArray($page);
        $cursor = $page['nextCursor'] ?? null;
        self::assertIsString($cursor);
        $this->client->request(
            'GET',
            '/api/v1/executor-permission-evidence?limit=1&'.$filters.'&cursor='.rawurlencode($cursor),
        );
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->readModel->query?->page->cursor);

        $this->client->request('POST', '/api/v1/executor-permission-evidence');
        self::assertResponseStatusCodeSame(405);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown' => ['?unexpected=1'];
        yield 'zero limit' => ['?limit=0'];
        yield 'large limit' => ['?limit=101'];
        yield 'array limit' => ['?limit%5B%5D=1'];
        yield 'malformed cursor' => ['?cursor=invalid'];
        foreach (['connectionId', 'clusterId', 'targetId', 'nodeId', 'guestId'] as $filter) {
            yield 'invalid '.$filter => ['?'.$filter.'=invalid'];
            yield 'array '.$filter => ['?'.$filter.'%5B%5D='.self::ID];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesFailBeforeTheReadModel(string $query): void
    {
        $this->client->request('GET', '/api/v1/executor-permission-evidence'.$query);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            ['error' => ['code' => 'invalid_query', 'message' => 'The query is invalid.']],
            $this->json(),
        );
        self::assertNull($this->readModel->query);
    }

    public function testForeignCursorAndReadFailureAreSanitized(): void
    {
        $foreign = PageCursor::resource(PageCursor::context('foreign'), self::ID, self::ID)->opaque();
        $this->client->request(
            'GET',
            '/api/v1/executor-permission-evidence?connectionId='.self::ID.'&cursor='.rawurlencode($foreign),
        );
        self::assertResponseStatusCodeSame(400);
        self::assertNull($this->readModel->query);

        $this->readModel->fail = true;
        $this->client->request('GET', '/api/v1/executor-permission-evidence');
        self::assertResponseStatusCodeSame(503);
        $encoded = (string) $this->client->getResponse()->getContent();
        self::assertSame(
            '{"error":{"code":"read_model_unavailable","message":"The read model is temporarily unavailable."}}',
            $encoded,
        );
        self::assertStringNotContainsString('database.internal', $encoded);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        $value = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        /** @var array<string, mixed> $value */
        return $value;
    }
}

final class ExecutorPermissionEvidenceReadModelFake implements ExecutorPermissionEvidenceReadModel
{
    public ?ExecutorPermissionEvidenceQuery $query = null;
    public bool $fail = false;

    public function evidence(ExecutorPermissionEvidenceQuery $query): ExecutorPermissionEvidencePage
    {
        $this->query = $query;
        if ($this->fail) {
            throw new RuntimeException('database.internal secret diagnostic');
        }
        $item = new ExecutorPermissionEvidenceItem(
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            ExecutorPermissionEvidenceApiTest::ID,
            new UInt64Decimal('9'),
            ExecutorPermissionEvidenceApiTest::OTHER,
            4,
            3,
            2,
            '2026-07-18T10:00:00.000000Z',
            EvidenceFreshness::Stale,
            true,
            false,
            false,
        );
        $next = null === $query->page->cursor
            ? PageCursor::resource($query->cursorContext(), $item->id, $item->id)
            : null;

        return new ExecutorPermissionEvidencePage($query->page, [$item], $next);
    }
}

final class ExecutorEvidenceAuthenticator implements HttpRequestAuthenticator
{
    public bool $authenticated = true;
    /** @var list<Permission> */
    public array $permissions = [Permission::InventoryRead];

    public function authenticate(Request $request): SessionResult
    {
        if (!$this->authenticated) {
            throw new AuthenticationFailed();
        }
        $now = new DateTimeImmutable('2026-07-18T10:00:00Z');

        return new SessionResult(
            str_repeat('s', 16),
            new AuthenticatedPrincipal(
                new UserId(str_repeat('u', 16)),
                new NormalizedUsername('operator'),
                $this->permissions,
                str_repeat('s', 16),
            ),
            new OpaqueToken(str_repeat("\x03", 32)),
            new SessionWindow($now, $now, $now->modify('+30 minutes'), $now->modify('+12 hours')),
        );
    }
}
