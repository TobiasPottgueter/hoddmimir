<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionDetail;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowDecisionSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationPage;
use App\Application\Scheduler\Shadow\ReadModel\ShadowEvaluationSummary;
use App\Application\Scheduler\Shadow\ReadModel\ShadowGateDetail;
use App\Application\Scheduler\Shadow\ReadModel\ShadowPageQuery;
use App\Application\Scheduler\Shadow\ReadModel\ShadowReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalShadowReadModel;
use App\Domain\Scheduler\BackupReason;
use App\Domain\Scheduler\DecisionOutcome;
use App\Domain\Scheduler\GateCode;
use App\Domain\Scheduler\GateDetailCode;
use App\Domain\Scheduler\GateScope;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShadowReadApiTest extends WebTestCase
{
    public const string ID = '00112233-4455-6677-8899-aabbccddeeff';
    public const string OTHER = '11112233-4455-6677-8899-aabbccddeeff';

    public function testCursorPagesAndOrderedDecisionDetailsAreExposedReadOnly(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new ShadowReadModelFake();
        self::getContainer()->set(DbalShadowReadModel::class, $fake);

        $client->request('GET', '/api/v1/shadow/evaluations?limit=1');
        self::assertResponseIsSuccessful();
        $evaluations = $this->payload($client->getResponse()->getContent());
        $evaluationItems = $evaluations['items'] ?? null;
        self::assertIsArray($evaluationItems);
        $evaluation = $evaluationItems[0] ?? null;
        self::assertIsArray($evaluation);
        self::assertSame(1, $evaluation['decisionCount'] ?? null);
        $evaluationPage = $evaluations['page'] ?? null;
        self::assertIsArray($evaluationPage);
        self::assertIsString($evaluationPage['nextCursor'] ?? null);
        self::assertSame(1, $fake->evaluationQuery?->page->limit);

        $client->request('GET', '/api/v1/shadow/decisions?limit=1');
        self::assertResponseIsSuccessful();
        $decisions = $this->payload($client->getResponse()->getContent());
        $decisionItems = $decisions['items'] ?? null;
        self::assertIsArray($decisionItems);
        $decision = $decisionItems[0] ?? null;
        self::assertIsArray($decision);
        self::assertSame('never_backed_up', $decision['reason'] ?? null);
        self::assertSame(300, $decision['priority'] ?? null);
        self::assertSame(1, $fake->decisionQuery?->page->limit);

        $client->request('GET', '/api/v1/shadow/decisions?outcome=eligible&reason=never_backed_up&policyId='.self::ID.'&targetId='.self::OTHER.'&guestId='.self::ID);
        self::assertResponseIsSuccessful();
        self::assertNotNull($fake->decisionQuery);
        self::assertSame(DecisionOutcome::Eligible, $fake->decisionQuery->outcome);
        self::assertSame(BackupReason::NeverBackedUp, $fake->decisionQuery->reason);
        self::assertSame(self::ID, $fake->decisionQuery->policyId);
        self::assertSame(self::OTHER, $fake->decisionQuery->targetId);
        self::assertSame(self::ID, $fake->decisionQuery->guestId);

        $client->request('GET', '/api/v1/shadow/decisions/'.self::ID);
        self::assertResponseIsSuccessful();
        $detail = $this->payload($client->getResponse()->getContent());
        $gates = $detail['gates'] ?? null;
        self::assertIsArray($gates);
        self::assertSame([1, 2], array_column($gates, 'position'));
        self::assertSame(self::ID, $fake->detailId);

        $client->request('POST', '/api/v1/shadow/decisions');
        self::assertResponseStatusCodeSame(405);
    }

    public function testUnknownDecisionReturnsStableNotFound(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new ShadowReadModelFake();
        $fake->missing = true;
        self::getContainer()->set(DbalShadowReadModel::class, $fake);

        $client->request('GET', '/api/v1/shadow/decisions/'.self::ID);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(
            '{"error":{"code":"not_found","message":"The shadow decision was not found."}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    #[DataProvider('invalidRouteProvider')]
    public function testInvalidQueriesFailBeforeTheReadModel(string $route): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new ShadowReadModelFake();
        self::getContainer()->set(DbalShadowReadModel::class, $fake);

        $client->request('GET', $route);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($fake->evaluationQuery);
        self::assertNull($fake->decisionQuery);
        self::assertNull($fake->detailId);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRouteProvider(): iterable
    {
        yield 'unknown evaluation filter' => ['/api/v1/shadow/evaluations?status=complete'];
        yield 'zero evaluation limit' => ['/api/v1/shadow/evaluations?limit=0'];
        yield 'array decision limit' => ['/api/v1/shadow/decisions?limit%5B%5D=1'];
        yield 'invalid opaque cursor' => ['/api/v1/shadow/decisions?cursor=internal'];
        yield 'invalid outcome' => ['/api/v1/shadow/decisions?outcome=unknown'];
        yield 'invalid reason' => ['/api/v1/shadow/decisions?reason=unknown'];
        yield 'invalid policy id' => ['/api/v1/shadow/decisions?policyId=not-a-uuid'];
        yield 'decision filter rejected for evaluations' => ['/api/v1/shadow/evaluations?outcome=eligible'];
        yield 'detail query parameter' => ['/api/v1/shadow/decisions/'.self::ID.'?expand=secret'];
        yield 'invalid detail id' => ['/api/v1/shadow/decisions/not-a-uuid'];
    }

    #[DataProvider('readFailureRouteProvider')]
    public function testReadFailuresReturnOnlyStableSafeErrors(string $route): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $fake = new ShadowReadModelFake();
        $fake->fail = true;
        self::getContainer()->set(DbalShadowReadModel::class, $fake);

        $client->request('GET', $route);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(
            '{"error":{"code":"read_model_unavailable","message":"The read model is temporarily unavailable."}}',
            (string) $client->getResponse()->getContent(),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function readFailureRouteProvider(): iterable
    {
        yield 'evaluations' => ['/api/v1/shadow/evaluations'];
        yield 'decisions' => ['/api/v1/shadow/decisions'];
        yield 'detail' => ['/api/v1/shadow/decisions/'.self::ID];
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
}

final class ShadowReadModelFake implements ShadowReadModel
{
    public ?ShadowPageQuery $evaluationQuery = null;
    public ?ShadowPageQuery $decisionQuery = null;
    public ?string $detailId = null;
    public bool $missing = false;
    public bool $fail = false;

    public function evaluations(ShadowPageQuery $query): ShadowEvaluationPage
    {
        $this->evaluationQuery = $query;
        $this->assertAvailable();
        $item = new ShadowEvaluationSummary(
            ShadowReadApiTest::ID,
            ShadowReadApiTest::OTHER,
            7,
            1,
            1,
            2,
            '2026-07-12T10:00:00.000000Z',
            '2026-07-12T10:00:01.000000Z',
            '2026-07-12T10:00:01.000001Z',
        );

        return new ShadowEvaluationPage(
            $query->page,
            [$item],
            PageCursor::resource($query->cursorContext(), $item->completedAt, $item->id),
        );
    }

    public function decisions(ShadowPageQuery $query): ShadowDecisionPage
    {
        $this->decisionQuery = $query;
        $this->assertAvailable();
        $item = $this->summary();

        return new ShadowDecisionPage(
            $query->page,
            [$item],
            PageCursor::resource($query->cursorContext(), $item->completedAt, $item->id),
        );
    }

    public function decision(string $id): ?ShadowDecisionDetail
    {
        $this->detailId = $id;
        $this->assertAvailable();
        if ($this->missing) {
            return null;
        }

        return new ShadowDecisionDetail($this->summary(), [
            new ShadowGateDetail(1, GateCode::ConnectionEnabled, true, GateScope::Connection, ShadowReadApiTest::ID, null, GateDetailCode::Passed),
            new ShadowGateDetail(2, GateCode::ExecutorAuthorizationFresh, false, GateScope::Authorization, ShadowReadApiTest::OTHER, '2026-07-12T09:00:00.000000Z', GateDetailCode::Stale),
        ]);
    }

    private function summary(): ShadowDecisionSummary
    {
        return new ShadowDecisionSummary(
            ShadowReadApiTest::ID,
            ShadowReadApiTest::OTHER,
            ShadowReadApiTest::ID,
            ShadowReadApiTest::OTHER,
            DecisionOutcome::Eligible,
            BackupReason::NeverBackedUp,
            300,
            ShadowReadApiTest::ID,
            2,
            ShadowReadApiTest::OTHER,
            3,
            '2026-07-12T10:00:01.000000Z',
        );
    }

    private function assertAvailable(): void
    {
        if ($this->fail) {
            throw new RuntimeException('database.internal secret');
        }
    }
}
