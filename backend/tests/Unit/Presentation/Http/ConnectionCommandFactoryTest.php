<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Presentation\Http\ConnectionCommandFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ConnectionCommandFactoryTest extends TestCase
{
    private const string CONNECTION = '00112233-4455-6677-8899-aabbccddeeff';
    private const string ENDPOINT = '11112222-3333-4444-8555-666677778888';

    public function testItBuildsOnlyTheSafeDisplayNameUpdate(): void
    {
        $factory = new ConnectionCommandFactory(new FixedConnectionIdentifierGenerator());
        $request = Request::create('/api/v1/connections/'.self::CONNECTION, 'PUT', server: [
            'HTTP_IDEMPOTENCY_KEY' => 'update-1',
        ], content: json_encode(['expectedRevision' => 7, 'displayName' => 'PVE production'], JSON_THROW_ON_ERROR));

        $command = $factory->fromRequest($request, ConfigurationCommandType::ConnectionUpdate, self::CONNECTION);

        self::assertSame(ConfigurationCommandType::ConnectionUpdate, $command->type);
        self::assertSame(hex2bin(str_replace('-', '', self::CONNECTION)), $command->subjectId);
        self::assertSame(7, $command->expectedRevision);
        self::assertSame(['displayName' => 'PVE production'], $command->payload);
        self::assertNull($command->secret);
    }

    public function testItBuildsTheSafeEndpointDisableWithCanonicalIdentifiers(): void
    {
        $factory = new ConnectionCommandFactory(new FixedConnectionIdentifierGenerator());
        $request = Request::create('/api/v1/connections/'.self::CONNECTION.'/endpoints/'.self::ENDPOINT.'/disable', 'POST', server: [
            'HTTP_IDEMPOTENCY_KEY' => 'disable-1',
            'HTTP_X_CORRELATION_ID' => self::ENDPOINT,
        ], content: json_encode(['expectedRevision' => 4], JSON_THROW_ON_ERROR));

        $command = $factory->fromRequest(
            $request,
            ConfigurationCommandType::EndpointDisable,
            self::CONNECTION,
            self::ENDPOINT,
        );

        self::assertSame(['endpointId' => hex2bin(str_replace('-', '', self::ENDPOINT))], $command->payload);
        self::assertSame(hex2bin(str_replace('-', '', self::ENDPOINT)), $command->correlationId);
    }

    #[DataProvider('removedLegacyTypes')]
    public function testItRejectsEveryRemovedLegacyCommandType(ConfigurationCommandType $type): void
    {
        $factory = new ConnectionCommandFactory(new FixedConnectionIdentifierGenerator());
        $request = Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'removed'], content: '{"expectedRevision":0}');

        $this->expectException(InvalidArgumentException::class);
        $factory->fromRequest($request, $type, self::CONNECTION);
    }

    /** @return iterable<string, array{ConfigurationCommandType}> */
    public static function removedLegacyTypes(): iterable
    {
        yield 'connection create' => [ConfigurationCommandType::ConnectionCreate];
        yield 'connection enable' => [ConfigurationCommandType::ConnectionEnable];
        yield 'endpoint create' => [ConfigurationCommandType::EndpointCreate];
        yield 'endpoint update' => [ConfigurationCommandType::EndpointUpdate];
        yield 'credential rotate' => [ConfigurationCommandType::CredentialRotate];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testItRejectsUnknownMissingAndMalformedSafeBodies(array $body): void
    {
        $factory = new ConnectionCommandFactory(new FixedConnectionIdentifierGenerator());
        $request = Request::create('/', 'PUT', server: ['HTTP_IDEMPOTENCY_KEY' => 'key'], content: json_encode($body, JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $factory->fromRequest($request, ConfigurationCommandType::ConnectionUpdate, self::CONNECTION);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidBodies(): iterable
    {
        yield 'unknown' => [['expectedRevision' => 0, 'displayName' => 'x', 'secret' => 'bad']];
        yield 'missing' => [['expectedRevision' => 0]];
        yield 'negative revision' => [['expectedRevision' => -1, 'displayName' => 'x']];
        yield 'string revision' => [['expectedRevision' => '1', 'displayName' => 'x']];
    }
}

final class FixedConnectionIdentifierGenerator implements SecurityIdentifierGenerator
{
    private int $counter = 0;

    public function generate(): string
    {
        return str_pad((string) ++$this->counter, 16, '0', STR_PAD_LEFT);
    }
}
