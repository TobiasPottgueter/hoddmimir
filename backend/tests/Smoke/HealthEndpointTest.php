<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use App\Application\Readiness\ReadinessAggregator;
use App\Application\Readiness\ReadinessCheckResult;
use App\Tests\Fakes\FixedReadinessCheck;
use JsonException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    /** @throws JsonException */
    public function testHealthEndpointIsAvailable(): void
    {
        $client = self::createClient();
        self::getContainer()->set(
            ReadinessAggregator::class,
            new ReadinessAggregator([
                new FixedReadinessCheck(ReadinessCheckResult::ready('database_schema')),
            ]),
        );
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();

        if (false === $content) {
            self::fail('The health response content could not be read.');
        }

        /** @var array{status?: mixed, checkedAt?: mixed, checks?: mixed} $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status'] ?? null);
        self::assertIsString($payload['checkedAt'] ?? null);
        self::assertSame(['database_schema' => ['status' => 'ready']], $payload['checks'] ?? null);
    }

    public function testHealthEndpointReturnsServiceUnavailableWhenSchemaIsNotReady(): void
    {
        $client = self::createClient();
        self::getContainer()->set(
            ReadinessAggregator::class,
            new ReadinessAggregator([
                new FixedReadinessCheck(ReadinessCheckResult::unavailable(
                    'database_schema',
                    'migration_version_mismatch',
                )),
            ]),
        );

        $client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(503);
        $content = $client->getResponse()->getContent();
        if (false === $content) {
            self::fail('The unavailable health response content could not be read.');
        }

        /** @var array{status?: mixed, checks?: mixed} $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unavailable', $payload['status'] ?? null);
        self::assertSame([
            'database_schema' => [
                'status' => 'unavailable',
                'reason' => 'migration_version_mismatch',
            ],
        ], $payload['checks'] ?? null);
    }
}
