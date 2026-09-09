<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Inventory\ReadModel\CollectorReadModel;
use App\Application\Inventory\ReadModel\CollectorRun;
use App\Application\Inventory\ReadModel\CollectorScope;
use App\Application\Inventory\ReadModel\CollectorScopeQuery;
use App\Application\Inventory\ReadModel\CollectorStatus;
use App\Application\Inventory\ReadModel\InventoryOverview;
use App\Application\Inventory\ReadModel\InventoryReadModel;
use App\Application\Inventory\ReadModel\InventoryResource;
use App\Application\Inventory\ReadModel\InventoryResourceKind;
use App\Application\Inventory\ReadModel\InventoryResourceQuery;
use App\Application\Inventory\ReadModel\InventoryState;
use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadPage;
use App\Infrastructure\Persistence\MariaDb\DbalInventoryReadModel;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InventoryReadApiTest extends WebTestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';

    /** @return iterable<string, array{string}> */
    public static function invalidResourceQueries(): iterable
    {
        yield 'missing kind' => [''];
        yield 'unknown parameter' => ['?kind=pve_node&unexpected=1'];
        yield 'array parameter' => ['?kind%5B%5D=pve_node'];
        yield 'unknown kind' => ['?kind=unknown'];
        yield 'unknown state' => ['?kind=pve_node&inventoryState=missing'];
        yield 'invalid id' => ['?kind=pve_node&connectionId=nope'];
        yield 'root parent' => ['?kind=pve_cluster&parentId='.self::UUID];
        yield 'guest type on node' => ['?kind=pve_node&guestType=qemu'];
        yield 'bad guest type' => ['?kind=pve_guest&guestType=openvz'];
        yield 'zero limit' => ['?kind=pve_node&limit=0'];
        yield 'large limit' => ['?kind=pve_node&limit=101'];
        yield 'obsolete offset' => ['?kind=pve_node&offset=1'];
        yield 'malformed cursor' => ['?kind=pve_node&cursor=internal-class-name'];
    }

    /** @throws JsonException */
    public function testVersionedReadEndpointsReturnBoundedPublicShapes(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $model = new ApiReadModelFake();
        self::getContainer()->set(DbalInventoryReadModel::class, $model);

        $client->request('GET', '/api/v1/inventory/overview');
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertIsArray($payload['counts']);
        self::assertSame(1, $payload['counts']['pveNodes']);

        $client->request('GET', '/api/v1/inventory/resources?kind=pve_guest&limit=1'
            .'&connectionId='.self::UUID.'&parentId='.self::UUID.'&inventoryState=archived&guestType=lxc');
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertIsArray($payload['items']);
        self::assertIsArray($payload['items'][0]);
        self::assertIsArray($payload['page']);
        self::assertSame('pve_guest', $payload['items'][0]['kind']);
        self::assertSame(1, $payload['page']['limit']);
        self::assertSame(1, $payload['page']['count']);
        self::assertTrue($payload['page']['hasMore']);
        self::assertIsString($payload['page']['nextCursor']);
        self::assertNotNull($model->resourceQuery);
        self::assertSame('lxc', $model->resourceQuery->guestType);

        $client->request('GET', '/api/v1/inventory/resources?kind=pve_guest&limit=1'
            .'&connectionId='.self::UUID.'&parentId='.self::UUID.'&inventoryState=archived&guestType=lxc'
            .'&cursor='.rawurlencode($payload['page']['nextCursor']));
        self::assertResponseIsSuccessful();
        self::assertNotNull($model->resourceQuery->page->cursor);

        $client->request('GET', '/api/v1/collector/status');
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertIsArray($payload['schedule']);
        self::assertTrue($payload['schedule']['configured']);

        $client->request('GET', '/api/v1/collector/runs?limit=1');
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertIsArray($payload['items']);
        self::assertIsArray($payload['items'][0]);
        self::assertIsArray($payload['page']);
        self::assertSame('succeeded', $payload['items'][0]['status']);
        self::assertIsString($payload['page']['nextCursor']);

        $client->request('GET', '/api/v1/collector/runs?limit=1&cursor='.
            rawurlencode($payload['page']['nextCursor']));
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/v1/collector/scopes?runId='.self::UUID.'&limit=1');
        self::assertResponseIsSuccessful();
        $payload = $this->payload($client->getResponse()->getContent());
        self::assertIsArray($payload['items']);
        self::assertIsArray($payload['items'][0]);
        self::assertSame('pve_guests', $payload['items'][0]['scopeType']);
        self::assertSame('access_denied', $payload['items'][0]['errorCode']);
        self::assertSame(self::UUID, $model->scopeQuery?->runId->value);

        $client->request('POST', '/api/v1/inventory/resources?kind=pve_node');
        self::assertResponseStatusCodeSame(405);
    }

    #[DataProvider('invalidResourceQueries')]
    public function testResourceQueryValidationFailsClosed(string $query): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $model = new ApiReadModelFake();
        self::getContainer()->set(DbalInventoryReadModel::class, $model);
        $client->request('GET', '/api/v1/inventory/resources'.$query);
        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            '{"error":{"code":"invalid_query","message":"The query is invalid."}}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertNull($model->resourceQuery);
    }

    public function testCollectorPaginationAndScopeValidationFailClosed(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $model = new ApiReadModelFake();
        self::getContainer()->set(DbalInventoryReadModel::class, $model);
        $impossibleDateCursor = self::opaque([
            'v' => 1,
            'kind' => 'collector_run',
            'context' => PageCursor::collectorRunsContext(),
            'first' => '2026-02-30T10:00:00.000000Z',
            'second' => self::UUID,
        ]);

        foreach ([
            '/api/v1/collector/runs?other=1',
            '/api/v1/collector/runs?limit=array',
            '/api/v1/collector/runs?cursor='.rawurlencode($impossibleDateCursor),
            '/api/v1/collector/scopes',
            '/api/v1/collector/scopes?runId=invalid',
            '/api/v1/collector/scopes?runId='.self::UUID.'&offset=1',
            '/api/v1/collector/scopes?runId='.self::UUID.'&cursor=InventoryResourceKind',
        ] as $path) {
            $client->request('GET', $path);
            self::assertResponseStatusCodeSame(400);
            self::assertSame(
                '{"error":{"code":"invalid_query","message":"The query is invalid."}}',
                (string) $client->getResponse()->getContent(),
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(string|false $content): array
    {
        self::assertIsString($content);
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @param array<string, int|string> $payload */
    private static function opaque(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
}

final class ApiReadModelFake implements InventoryReadModel, CollectorReadModel
{
    public ?InventoryResourceQuery $resourceQuery = null;
    public ?CollectorScopeQuery $scopeQuery = null;

    public function overview(): InventoryOverview
    {
        return new InventoryOverview('2026-07-12T10:00:00.000000Z', null, ['pveNodes' => 1]);
    }

    public function resources(InventoryResourceQuery $query): ReadPage
    {
        $this->resourceQuery = $query;
        $resource = new InventoryResource(
            self::uuid(), InventoryResourceKind::PveGuest, self::uuid(), 'PVE', self::uuid(), 'ct-101',
            InventoryState::Archived, '2026-07-12T09:00:00.000000Z', '2026-07-12T09:30:00.000000Z',
            '2026-07-12T09:31:00.000000Z', null, ['guestType' => 'lxc', 'vmid' => 101],
        );
        $cursor = null === $query->page->cursor
            ? PageCursor::resource($query->cursorContext(), $resource->displayName, $resource->id)
            : null;

        return new ReadPage($query->page, [$resource], $cursor);
    }

    public function status(): CollectorStatus
    {
        return new CollectorStatus('2026-07-12T10:00:00.000000Z', ['configured' => true], null);
    }

    public function runs(PageRequest $page): ReadPage
    {
        $run = new CollectorRun(
            self::uuid(), self::uuid(), 'PVE', 'pve', 'succeeded', true,
            '2026-07-12T09:00:00.000000Z', '2026-07-12T09:01:00.000000Z',
            '2026-07-12T09:01:00.000000Z', 1, 2, 3, null,
        );

        return new ReadPage(
            $page,
            [$run],
            null === $page->cursor ? PageCursor::collectorRun($run->startedAt, $run->id) : null,
        );
    }

    public function scopes(CollectorScopeQuery $query): ReadPage
    {
        $this->scopeQuery = $query;
        return new ReadPage($query->page, [new CollectorScope(
            self::uuid(), 'pve_guests', '@installation', 'complete', '2026-07-12T09:01:00.000000Z',
            'access_denied',
        )], null);
    }

    private static function uuid(): string
    {
        return '00112233-4455-6677-8899-aabbccddeeff';
    }
}
