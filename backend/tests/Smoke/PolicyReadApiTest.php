<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Policy\ReadModel\ConfiguredPolicy;
use App\Application\Policy\ReadModel\PolicyBlockerCode;
use App\Application\Policy\ReadModel\PolicyListQuery;
use App\Application\Policy\ReadModel\PolicyPage;
use App\Application\Policy\ReadModel\PolicyReadModel;
use App\Application\Policy\ReadModel\PolicySelectionEntry;
use App\Application\Policy\ReadModel\PolicySelectionPage;
use App\Application\Policy\ReadModel\PolicySelectionQuery;
use App\Infrastructure\Persistence\MariaDb\DbalPolicyReadModel;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PolicyReadApiTest extends WebTestCase
{
    public const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    public const string OTHER = '11112233-4455-6677-8899-aabbccddeeff';

    public function testReadOnlyRoutesReturnClosedCursorPagesWithoutActivation(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new PolicyReadModelFake();
        self::getContainer()->set(DbalPolicyReadModel::class, $fake);

        $client->request('GET', '/api/v1/policies?limit=1&search=Night&status=draft');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        self::assertSame(false, $item['canEnable'] ?? null);
        self::assertSame(false, $item['retentionExecutionEnabled'] ?? null);
        self::assertSame([
            'executor_evidence_missing',
            'retention_execution_forbidden_for_pbs_target',
        ], $item['blockers'] ?? null);
        $page = $payload['page'] ?? null;
        self::assertIsArray($page);
        self::assertIsString($page['nextCursor'] ?? null);
        self::assertSame('Night', $fake->policyQuery?->search);

        $client->request('GET', '/api/v1/policies/'.self::ID.'/selection?limit=1');
        self::assertResponseIsSuccessful();
        $selection = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($selection);
        $selectionItems = $selection['items'] ?? null;
        self::assertIsArray($selectionItems);
        $selectionItem = $selectionItems[0] ?? null;
        self::assertIsArray($selectionItem);
        self::assertSame('assignment', $selectionItem['kind'] ?? null);
        self::assertSame(self::ID, $fake->selectionQuery?->policyId);

        $client->request('POST', '/api/v1/policies');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            ['error' => ['code' => 'permission_denied']],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
        $client->request('POST', '/api/v1/policies/'.self::ID.'/selection');
        self::assertResponseStatusCodeSame(405);
    }

    #[DataProvider('invalidRouteProvider')]
    public function testInvalidQueriesFailBeforeReadModel(string $route): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new PolicyReadModelFake();
        self::getContainer()->set(DbalPolicyReadModel::class, $fake);
        $client->request('GET', $route);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($fake->policyQuery);
        self::assertNull($fake->selectionQuery);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRouteProvider(): iterable
    {
        yield 'policy unknown query' => ['/api/v1/policies?unknown=1'];
        yield 'policy bad limit' => ['/api/v1/policies?limit=0'];
        yield 'policy array' => ['/api/v1/policies?status%5B%5D=draft'];
        yield 'policy padded' => ['/api/v1/policies?search=%20Night'];
        yield 'policy status' => ['/api/v1/policies?status=active'];
        yield 'selection unknown query' => ['/api/v1/policies/'.self::ID.'/selection?search=x'];
        yield 'selection invalid id' => ['/api/v1/policies/invalid/selection'];
        yield 'selection bad cursor' => ['/api/v1/policies/'.self::ID.'/selection?cursor=internal'];
    }

    public function testReadFailuresReturnOnlyStableSafeError(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new PolicyReadModelFake();
        $fake->fail = true;
        self::getContainer()->set(DbalPolicyReadModel::class, $fake);

        foreach (['/api/v1/policies', '/api/v1/policies/'.self::ID.'/selection'] as $route) {
            $client->request('GET', $route);
            self::assertResponseStatusCodeSame(503);
            self::assertSame(
                '{"error":{"code":"read_model_unavailable","message":"The read model is temporarily unavailable."}}',
                (string) $client->getResponse()->getContent(),
            );
        }
    }
}

final class PolicyReadModelFake implements PolicyReadModel
{
    public ?PolicyListQuery $policyQuery = null;
    public ?PolicySelectionQuery $selectionQuery = null;
    public bool $fail = false;

    public function policies(PolicyListQuery $query): PolicyPage
    {
        $this->policyQuery = $query;
        if ($this->fail) {
            throw new RuntimeException('database.internal');
        }
        return new PolicyPage($query->page, [new ConfiguredPolicy(
            PolicyReadApiTest::ID, 1, 'draft', 'Nightly', PolicyReadApiTest::ID, 'PVE',
            PolicyReadApiTest::OTHER, 'cluster-a', null, null, null, null, null, null,
            null, null, null, null, false, null, [
                PolicyBlockerCode::ExecutorEvidenceMissing,
                PolicyBlockerCode::RetentionExecutionForbiddenForPbsTarget,
            ],
            failureNotificationRecipients: [],
        )], PageCursor::resource($query->cursorContext(), 'Nightly', PolicyReadApiTest::ID));
    }

    public function selection(PolicySelectionQuery $query): PolicySelectionPage
    {
        $this->selectionQuery = $query;
        if ($this->fail) {
            throw new RuntimeException('database.internal');
        }
        return new PolicySelectionPage($query->page, [new PolicySelectionEntry(
            PolicyReadApiTest::ID, 1, 'active', 'assignment', 'global', null, null,
            null, null, 'All policy subjects', 'include', null, null, null, null,
        )], null);
    }
}
