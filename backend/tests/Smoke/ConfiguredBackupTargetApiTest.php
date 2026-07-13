<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Target\ReadModel\ConfiguredBackupTarget;
use App\Application\Target\ReadModel\ConfiguredBackupTargetBlockerCode;
use App\Application\Target\ReadModel\ConfiguredBackupTargetPage;
use App\Application\Target\ReadModel\ConfiguredBackupTargetQuery;
use App\Application\Target\ReadModel\ConfiguredBackupTargetReadModel;
use App\Infrastructure\Persistence\MariaDb\DbalConfiguredBackupTargetReadModel;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConfiguredBackupTargetApiTest extends WebTestCase
{
    public const string ID = '00112233-4455-6677-8899-aabbccddeeff';

    /** @return iterable<string, array{string}> */
    public static function invalidQueries(): iterable
    {
        yield 'unknown' => ['?unexpected=1'];
        yield 'array' => ['?enabled%5B%5D=false'];
        yield 'empty search' => ['?search='];
        yield 'padded search' => ['?search=%20target'];
        yield 'invalid enabled' => ['?enabled=0'];
        yield 'zero limit' => ['?limit=0'];
        yield 'large limit' => ['?limit=101'];
        yield 'malformed cursor' => ['?cursor=internal'];
    }

    public function testGetReturnsClosedReadOnlyProjectionAndFilterBoundCursor(): void
    {
        $client = self::createClient();
        $model = new ConfiguredBackupTargetReadModelFake();
        self::getContainer()->set(DbalConfiguredBackupTargetReadModel::class, $model);

        $client->request('GET', '/api/v1/backup-targets?limit=1&search=Nightly&enabled=false');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        $items = $payload['items'] ?? null;
        self::assertIsArray($items);
        $item = $items[0] ?? null;
        self::assertIsArray($item);
        self::assertSame(false, $item['canEnable'] ?? null);
        self::assertSame(
            ['configuration_incomplete'],
            $item['blockers'] ?? null,
        );
        self::assertNotNull($model->query);
        self::assertSame('Nightly', $model->query->search);
        self::assertFalse($model->query->enabled);
        $page = $payload['page'] ?? null;
        self::assertIsArray($page);
        self::assertIsString($page['nextCursor'] ?? null);

        $client->request('POST', '/api/v1/backup-targets');
        self::assertResponseStatusCodeSame(403);
        self::assertSame(
            ['error' => ['code' => 'permission_denied']],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryFailsBeforeReadModel(string $query): void
    {
        $client = self::createClient();
        $model = new ConfiguredBackupTargetReadModelFake();
        self::getContainer()->set(DbalConfiguredBackupTargetReadModel::class, $model);
        $client->request('GET', '/api/v1/backup-targets'.$query);

        self::assertResponseStatusCodeSame(400);
        self::assertNull($model->query);
    }

    public function testFailureReturnsOnlySafeStableError(): void
    {
        $client = self::createClient();
        $model = new ConfiguredBackupTargetReadModelFake();
        $model->fail = true;
        self::getContainer()->set(DbalConfiguredBackupTargetReadModel::class, $model);
        $client->request('GET', '/api/v1/backup-targets');

        self::assertResponseStatusCodeSame(503);
        $body = (string) $client->getResponse()->getContent();
        self::assertSame(
            '{"error":{"code":"read_model_unavailable","message":"The read model is temporarily unavailable."}}',
            $body,
        );
        self::assertStringNotContainsString('database.internal', $body);
    }
}

final class ConfiguredBackupTargetReadModelFake implements ConfiguredBackupTargetReadModel
{
    public ?ConfiguredBackupTargetQuery $query = null;
    public bool $fail = false;

    public function targets(ConfiguredBackupTargetQuery $query): ConfiguredBackupTargetPage
    {
        $this->query = $query;
        if ($this->fail) {
            throw new RuntimeException('database.internal secret');
        }
        $target = new ConfiguredBackupTarget(
            ConfiguredBackupTargetApiTest::ID,
            1,
            false,
            'Nightly',
            ConfiguredBackupTargetApiTest::ID,
            'PVE',
            ConfiguredBackupTargetApiTest::ID,
            'cluster',
            ConfiguredBackupTargetApiTest::ID,
            'backup',
            'dir',
            null,
            null,
            null,
            null,
            null,
            '2026-07-12T10:00:00.000000Z',
            [],
            [
                ConfiguredBackupTargetBlockerCode::ConfigurationIncomplete,
            ],
        );
        return new ConfiguredBackupTargetPage(
            $query->page,
            [$target],
            PageCursor::resource($query->cursorContext(), $target->displayName, $target->id),
        );
    }
}
