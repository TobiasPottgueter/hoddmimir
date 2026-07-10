<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use JsonException;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthEndpointTest extends WebTestCase
{
    /** @throws JsonException */
    public function testHealthEndpointIsAvailable(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $content = $client->getResponse()->getContent();

        if (false === $content) {
            self::fail('The health response content could not be read.');
        }

        /** @var array{status?: mixed, checkedAt?: mixed} $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ok', $payload['status'] ?? null);
        self::assertIsString($payload['checkedAt'] ?? null);
    }
}
