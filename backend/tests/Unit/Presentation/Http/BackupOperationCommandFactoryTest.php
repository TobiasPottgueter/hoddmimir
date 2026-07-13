<?php

declare(strict_types=1);

namespace App\Tests\Unit\Presentation\Http;

use App\Application\Backup\Operations\BackupOperationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Presentation\Http\BackupOperationCommandFactory;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class BackupOperationCommandFactoryTest extends TestCase
{
    public function testManualCommandHasServerGeneratedRequestAndCorrelationIdentifiers(): void
    {
        $factory = new BackupOperationCommandFactory(new SequentialIds());
        $request = Request::create('/api/v1/operations/requests', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: json_encode([
            'guestId'=>'11111111-1111-1111-1111-111111111111',
            'policyId'=>'22222222-2222-2222-2222-222222222222',
            'expectedRevision'=>7,
        ], JSON_THROW_ON_ERROR));
        $command = $factory->manual($request);
        self::assertSame(BackupOperationCommandType::ManualRequest, $command->type);
        self::assertSame(str_repeat("\x01",16), $command->subjectId);
        self::assertSame(str_repeat("\x02",16), $command->correlationId);
        self::assertSame(7, $command->expectedRevision);
    }

    public function testUnknownBodyMemberIsRejected(): void
    {
        $factory = new BackupOperationCommandFactory(new SequentialIds());
        $request = Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: '{"guestId":"11111111-1111-1111-1111-111111111111","policyId":"22222222-2222-2222-2222-222222222222","expectedRevision":1,"secret":"x"}');
        $this->expectException(InvalidArgumentException::class);
        $factory->manual($request);
    }

    public function testCancelUsesCanonicalPathIdExplicitCorrelationAndNilSelectionIds(): void
    {
        $factory = new BackupOperationCommandFactory(new SequentialIds());
        $request = Request::create('/', 'POST', server: [
            'HTTP_IDEMPOTENCY_KEY'=>'cancel-1',
            'HTTP_X_CORRELATION_ID'=>'33333333-3333-3333-3333-333333333333',
        ], content: '{"expectedRevision":9}');

        $command = $factory->cancel($request, '11111111-1111-1111-1111-111111111111');
        self::assertSame(BackupOperationCommandType::CancelRequest, $command->type);
        self::assertSame(str_repeat("\0", 16), $command->policyId);
        self::assertSame(str_repeat("\0", 16), $command->guestId);
        self::assertSame(str_repeat("\x33", 16), $command->correlationId);
        self::assertSame(9, $command->expectedRevision);
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('invalidManualRequests')]
    public function testMalformedManualInputsFailClosed(Request $request, string $exception): void
    {
        $factory = new BackupOperationCommandFactory(new SequentialIds());
        $this->expectException($exception);
        $factory->manual($request);
    }

    /** @return iterable<string, array{Request, class-string<\Throwable>}> */
    public static function invalidManualRequests(): iterable
    {
        $valid = ['guestId'=>'11111111-1111-1111-1111-111111111111','policyId'=>'22222222-2222-2222-2222-222222222222','expectedRevision'=>1];
        yield 'invalid json' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: '{'), JsonException::class];
        yield 'list body' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: '[]'), InvalidArgumentException::class];
        yield 'missing member' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: json_encode(array_diff_key($valid, ['guestId'=>true]), JSON_THROW_ON_ERROR)), InvalidArgumentException::class];
        yield 'missing idempotency key' => [Request::create('/', 'POST', content: json_encode($valid, JSON_THROW_ON_ERROR)), InvalidArgumentException::class];
        yield 'invalid guest uuid' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: json_encode(array_replace($valid, ['guestId'=>'not-a-uuid']), JSON_THROW_ON_ERROR)), InvalidArgumentException::class];
        yield 'invalid revision type' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: json_encode(array_replace($valid, ['expectedRevision'=>'1']), JSON_THROW_ON_ERROR)), InvalidArgumentException::class];
        yield 'negative revision' => [Request::create('/', 'POST', server: ['HTTP_IDEMPOTENCY_KEY'=>'manual-1'], content: json_encode(array_replace($valid, ['expectedRevision'=>-1]), JSON_THROW_ON_ERROR)), InvalidArgumentException::class];
    }
}

final class SequentialIds implements SecurityIdentifierGenerator
{
    private int $next = 1;
    public function generate(): string { $value = min(255, $this->next++); return str_repeat(pack('C', $value), 16); }
}
