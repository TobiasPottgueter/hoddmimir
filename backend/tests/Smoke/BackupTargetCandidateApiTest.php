<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Target\ReadModel\BackupTargetBlockerCode;
use App\Application\Target\ReadModel\BackupTargetCandidate;
use App\Application\Target\ReadModel\BackupTargetCandidatePage;
use App\Application\Target\ReadModel\BackupTargetCandidateQuery;
use App\Application\Target\ReadModel\BackupTargetCandidateReadModel;
use App\Application\Target\ReadModel\BackupTargetCapacityStatus;
use App\Application\Target\ReadModel\BackupTargetExecutorEvidence;
use App\Application\Target\ReadModel\BackupTargetExecutorStatus;
use App\Application\Target\ReadModel\EvidenceFreshness;
use App\Application\Target\ReadModel\BackupTargetNodeEvidence;
use App\Domain\Shared\UInt64Decimal;
use App\Infrastructure\Persistence\MariaDb\DbalBackupTargetCandidateReadModel;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BackupTargetCandidateApiTest extends WebTestCase
{
    public const string UUID = '00112233-4455-6677-8899-aabbccddeeff';
    public const string CLUSTER_UUID = '11112233-4455-6677-8899-aabbccddeeff';

    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown parameter' => ['?unexpected=1'];
        yield 'array limit' => ['?limit%5B%5D=1'];
        yield 'array identifier' => ['?connectionId%5B%5D='.self::UUID];
        yield 'obsolete offset' => ['?offset=1'];
        yield 'unsupported node filter' => ['?nodeId='.self::UUID];
        yield 'unsupported enable filter' => ['?canEnable=true'];
        yield 'zero limit' => ['?limit=0'];
        yield 'large limit' => ['?limit=101'];
        yield 'negative limit' => ['?limit=-1'];
        yield 'invalid connection id' => ['?connectionId=invalid'];
        yield 'invalid cluster id' => ['?clusterId=invalid'];
        yield 'malformed cursor' => ['?cursor=internal-class-name'];
    }

    public function testGetReturnsClosedFailClosedCandidateAndContextBoundCursor(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $model = new BackupTargetCandidateReadModelFake();
        self::getContainer()->set(DbalBackupTargetCandidateReadModel::class, $model);

        $filters = '?limit=1&connectionId='.self::UUID.'&clusterId='.self::CLUSTER_UUID;
        $client->request('GET', '/api/v1/backup-target-candidates'.$filters);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        self::assertSame(
            [
                'id', 'connectionId', 'connectionName', 'clusterId', 'clusterName', 'storageName',
                'storageType', 'shared', 'inventoryState', 'observedAt', 'canEnable', 'nodes', 'executor', 'pbs',
                'blockers',
            ],
            array_keys($item),
        );
        $nodes = $item['nodes'] ?? null;
        self::assertIsArray($nodes);
        $node = $nodes[0] ?? null;
        self::assertIsArray($node);
        self::assertSame('18446744073709551615', $node['totalBytes'] ?? null);
        self::assertFalse($item['canEnable'] ?? null);
        self::assertSame(['executor_evidence_missing'], $item['blockers'] ?? null);
        $page = $payload['page'] ?? null;
        self::assertIsArray($page);
        $nextCursor = $page['nextCursor'] ?? null;
        self::assertIsString($nextCursor);
        $captured = $model->query;
        self::assertNotNull($captured);
        self::assertSame(self::UUID, $captured->connectionId?->value);
        self::assertSame(self::CLUSTER_UUID, $captured->clusterId?->value);

        $client->request(
            'GET',
            '/api/v1/backup-target-candidates'.$filters.'&cursor='.
                rawurlencode($nextCursor),
        );
        self::assertResponseIsSuccessful();
        $continued = $model->query;
        self::assertNotNull($continued);
        self::assertNotNull($continued->page->cursor);

        $client->request('POST', '/api/v1/backup-target-candidates');
        self::assertResponseStatusCodeSame(405);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesFailClosedBeforeCallingTheReadModel(string $query): void
    {
        $client = self::createClient();
        $model = new BackupTargetCandidateReadModelFake();
        self::getContainer()->set(DbalBackupTargetCandidateReadModel::class, $model);

        $client->request('GET', '/api/v1/backup-target-candidates'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(
            '{"error":{"code":"invalid_query","message":"The query is invalid."}}',
            (string) $client->getResponse()->getContent(),
        );
        self::assertNull($model->query);
    }

    public function testCursorFromDifferentFiltersFailsClosed(): void
    {
        $client = self::createClient();
        $model = new BackupTargetCandidateReadModelFake();
        self::getContainer()->set(DbalBackupTargetCandidateReadModel::class, $model);
        $foreignContext = PageCursor::context('backup-target-candidates-v1', self::UUID, '');
        $cursor = PageCursor::resource($foreignContext, 'backup', self::UUID)->opaque();

        $client->request(
            'GET',
            '/api/v1/backup-target-candidates?clusterId='.self::CLUSTER_UUID.'&cursor='.rawurlencode($cursor),
        );

        self::assertResponseStatusCodeSame(400);
        self::assertNull($model->query);
    }

    public function testReadModelFailureReturnsOnlyTheStableSafeError(): void
    {
        $client = self::createClient();
        $model = new BackupTargetCandidateReadModelFake();
        $model->fail = true;
        self::getContainer()->set(DbalBackupTargetCandidateReadModel::class, $model);

        $client->request('GET', '/api/v1/backup-target-candidates');

        self::assertResponseStatusCodeSame(503);
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(
            '{"error":{"code":"read_model_unavailable","message":"The read model is temporarily unavailable."}}',
            $body,
        );
        self::assertStringNotContainsString('database.example.internal', $body);
    }
}

final class BackupTargetCandidateReadModelFake implements BackupTargetCandidateReadModel
{
    public ?BackupTargetCandidateQuery $query = null;
    public bool $fail = false;

    public function candidates(BackupTargetCandidateQuery $query): BackupTargetCandidatePage
    {
        $this->query = $query;
        if ($this->fail) {
            throw new RuntimeException('database.example.internal secret diagnostic');
        }
        $node = new BackupTargetNodeEvidence(
            BackupTargetCandidateApiTest::UUID,
            'pve-a',
            true,
            true,
            true,
            BackupTargetCapacityStatus::Measured,
            new UInt64Decimal('18446744073709551615'),
            new UInt64Decimal('1'),
            new UInt64Decimal('18446744073709551614'),
            '2026-07-12T10:00:00.000000Z',
            [],
        );
        $candidate = new BackupTargetCandidate(
            BackupTargetCandidateApiTest::UUID,
            BackupTargetCandidateApiTest::UUID,
            'PVE Lab',
            BackupTargetCandidateApiTest::CLUSTER_UUID,
            'cluster-a',
            'backup',
            'dir',
            true,
            'active',
            '2026-07-12T10:00:00.000000Z',
            [$node],
            null,
            [BackupTargetBlockerCode::ExecutorEvidenceMissing],
            new BackupTargetExecutorEvidence(
                BackupTargetExecutorStatus::Missing, 1, 1, 0, null, null, null,
                EvidenceFreshness::Missing, null, [BackupTargetBlockerCode::ExecutorEvidenceMissing],
            ),
        );
        $next = null === $query->page->cursor
            ? PageCursor::resource($query->cursorContext(), $candidate->storageName, $candidate->id)
            : null;

        return new BackupTargetCandidatePage($query->page, [$candidate], $next);
    }
}
