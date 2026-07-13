<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Backup;

use App\Domain\Backup\BackupProblemCode;
use App\Domain\Backup\BackupProblemNotificationAction;
use App\Domain\Backup\BackupProblemNotificationDecision;
use App\Domain\Backup\BackupProblemNotificationPolicy;
use App\Domain\Backup\BackupProblemState;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BackupProblemNotificationPolicyTest extends TestCase
{
    private const string START = '2026-07-12T10:00:00.000000Z';

    public function testFirstFailureOpensAndNotifiesExactlyOnce(): void
    {
        $decision = (new BackupProblemNotificationPolicy())->recordFailure(
            BackupProblemState::healthy(),
            BackupProblemCode::TaskFailed,
            $this->at(self::START),
        );

        self::assertSame(BackupProblemNotificationAction::Opened, $decision->action);
        self::assertSame(BackupProblemCode::TaskFailed, $decision->reportedCode);
        self::assertSame(1, $decision->reportedFailures);
        self::assertTrue($decision->state->isOpen());
        self::assertSame(1, $decision->state->consecutiveFailures);
    }

    public function testEveryRepeatedFailureNotifiesImmediatelyWithAttemptNumber(): void
    {
        $current = $this->open();
        $decision = (new BackupProblemNotificationPolicy())->recordFailure(
            $current,
            BackupProblemCode::TaskFailed,
            $this->at('2026-07-12T15:59:59.999999Z'),
        );

        self::assertSame(BackupProblemNotificationAction::RepeatedFailure, $decision->action);
        self::assertSame(BackupProblemCode::TaskFailed, $decision->reportedCode);
        self::assertSame(2, $decision->reportedFailures);
        self::assertSame(2, $decision->state->consecutiveFailures);
        self::assertEquals($this->at('2026-07-12T15:59:59.999999Z'), $decision->state->lastNotifiedAt);
    }

    public function testEveryLaterFailureProducesANotificationWithoutTimeBoundary(): void
    {
        $policy = new BackupProblemNotificationPolicy();
        $first = $policy->recordFailure(
            $this->open(),
            BackupProblemCode::TaskFailed,
            $this->at('2026-07-12T16:00:00.000000Z'),
        );
        $second = $policy->recordFailure(
            $first->state,
            BackupProblemCode::TaskFailed,
            $this->at('2026-07-12T22:00:00.000000Z'),
        );

        self::assertSame(BackupProblemNotificationAction::RepeatedFailure, $first->action);
        self::assertSame(2, $first->reportedFailures);
        self::assertSame(BackupProblemNotificationAction::RepeatedFailure, $second->action);
        self::assertSame(3, $second->reportedFailures);
    }

    public function testChangedProblemNotifiesImmediatelyWithoutResettingFailureCount(): void
    {
        $decision = (new BackupProblemNotificationPolicy())->recordFailure(
            $this->open(),
            BackupProblemCode::CapacityBlocked,
            $this->at('2026-07-12T10:01:00.000000Z'),
        );

        self::assertSame(BackupProblemNotificationAction::Changed, $decision->action);
        self::assertSame(BackupProblemCode::CapacityBlocked, $decision->state->code);
        self::assertSame(2, $decision->state->consecutiveFailures);
        self::assertSame(BackupProblemCode::CapacityBlocked, $decision->reportedCode);
    }

    public function testSuccessSendsOneRecoveryAndReturnsToHealthyState(): void
    {
        $policy = new BackupProblemNotificationPolicy();
        $recovery = $policy->recordSuccess(
            $this->open(4),
            $this->at('2026-07-12T11:00:00.000000Z'),
        );
        $again = $policy->recordSuccess(
            $recovery->state,
            $this->at('2026-07-12T12:00:00.000000Z'),
        );

        self::assertSame(BackupProblemNotificationAction::Resolved, $recovery->action);
        self::assertSame(BackupProblemCode::TaskFailed, $recovery->reportedCode);
        self::assertSame(4, $recovery->reportedFailures);
        self::assertFalse($recovery->state->isOpen());
        self::assertSame(BackupProblemNotificationAction::None, $again->action);
        self::assertEquals(BackupProblemState::healthy(), $again->state);
    }

    #[DataProvider('nonUtcOperationProvider')]
    public function testOperationsRejectNonUtcTimestamps(string $operation): void
    {
        $policy = new BackupProblemNotificationPolicy();
        $timestamp = new DateTimeImmutable('2026-07-12T12:00:00+02:00');
        $this->expectException(InvalidArgumentException::class);
        if ('failure' === $operation) {
            $policy->recordFailure(BackupProblemState::healthy(), BackupProblemCode::TaskFailed, $timestamp);
        } else {
            $policy->recordSuccess($this->open(), $timestamp);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonUtcOperationProvider(): iterable
    {
        yield 'failure' => ['failure'];
        yield 'success' => ['success'];
    }

    #[DataProvider('outOfOrderProvider')]
    public function testProblemTimelineMustRemainMonotonic(string $operation): void
    {
        $policy = new BackupProblemNotificationPolicy();
        $this->expectException(InvalidArgumentException::class);
        if ('failure' === $operation) {
            $policy->recordFailure(
                $this->open(),
                BackupProblemCode::TaskFailed,
                $this->at('2026-07-12T09:59:59.999999Z'),
            );
        } else {
            $policy->recordSuccess($this->open(), $this->at('2026-07-12T09:59:59.999999Z'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function outOfOrderProvider(): iterable
    {
        yield 'failure' => ['failure'];
        yield 'success' => ['success'];
    }

    public function testExhaustedFailureCounterFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BackupProblemNotificationPolicy())->recordFailure(
            $this->open(PHP_INT_MAX),
            BackupProblemCode::TaskFailed,
            $this->at('2026-07-12T11:00:00.000000Z'),
        );
    }

    #[DataProvider('invalidStateProvider')]
    public function testPersistedProblemStateIsValidated(
        int $failures,
        string $openedAt,
        string $occurredAt,
        string $notifiedAt,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        BackupProblemState::open(
            BackupProblemCode::TaskFailed,
            $failures,
            new DateTimeImmutable($openedAt),
            new DateTimeImmutable($occurredAt),
            new DateTimeImmutable($notifiedAt),
        );
    }

    #[DataProvider('invalidDecisionProvider')]
    public function testNotificationDecisionMustMatchItsPayload(
        BackupProblemNotificationAction $action,
        BackupProblemState $state,
        ?BackupProblemCode $code,
        int $failures,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        new BackupProblemNotificationDecision($action, $state, $code, $failures);
    }

    /** @return iterable<string, array{BackupProblemNotificationAction, BackupProblemState, ?BackupProblemCode, int}> */
    public static function invalidDecisionProvider(): iterable
    {
        $at = new DateTimeImmutable(self::START);
        $open = BackupProblemState::open(BackupProblemCode::TaskFailed, 1, $at, $at, $at);
        yield 'none cannot report' => [BackupProblemNotificationAction::None, BackupProblemState::healthy(), BackupProblemCode::TaskFailed, 1];
        yield 'none cannot report a failure count without a code' => [BackupProblemNotificationAction::None, BackupProblemState::healthy(), null, 1];
        yield 'opened must report' => [BackupProblemNotificationAction::Opened, $open, null, 0];
        yield 'resolved must close state' => [BackupProblemNotificationAction::Resolved, $open, BackupProblemCode::TaskFailed, 1];
        yield 'opened must retain state' => [BackupProblemNotificationAction::Opened, BackupProblemState::healthy(), BackupProblemCode::TaskFailed, 1];
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function invalidStateProvider(): iterable
    {
        yield 'zero failures' => [0, self::START, self::START, self::START];
        yield 'occurrence before open' => [1, self::START, '2026-07-12T09:59:59Z', self::START];
        yield 'notification before open' => [1, self::START, self::START, '2026-07-12T09:59:59Z'];
        yield 'non UTC' => [1, '2026-07-12T12:00:00+02:00', self::START, self::START];
    }

    private function open(int $failures = 1): BackupProblemState
    {
        $opened = $this->at(self::START);

        return BackupProblemState::open(
            BackupProblemCode::TaskFailed,
            $failures,
            $opened,
            $opened,
            $opened,
        );
    }

    private function at(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value);
    }
}
