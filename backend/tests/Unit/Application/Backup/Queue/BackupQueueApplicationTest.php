<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Backup\Queue;

use App\Application\Backup\Queue\BackupQueueStore;
use App\Application\Backup\Queue\ClaimedBackupRequest;
use App\Application\Backup\Queue\ClaimNextBackup;
use App\Application\Backup\Queue\ClaimNextBackupCommand;
use App\Application\Backup\Queue\ExpectedBackupSize;
use App\Application\Backup\Queue\FinalizeClaimedBackup;
use App\Application\Backup\Queue\FinalizeClaimedBackupCommand;
use App\Application\Backup\Queue\PromoteEligibleShadowDecision;
use App\Application\Backup\Queue\ShadowPromotion;
use App\Domain\Shared\UInt64Decimal;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BackupQueueApplicationTest extends TestCase
{
    public const string ID = 'iiiiiiiiiiiiiiii';

    public function testExpectedSizeUsesLastSuccessWithCeilingTenPercentOrProvisionedFallback(): void
    {
        $calculator = new ExpectedBackupSize();

        self::assertNull($calculator->calculate(null, null));
        self::assertSame('0', $calculator->calculate(null, '0')?->value);
        self::assertSame('123', $calculator->calculate(null, '123')?->value);
        self::assertSame('0', $calculator->calculate('0', '999')?->value);
        self::assertSame('2', $calculator->calculate('1', '999')?->value);
        self::assertSame('11', $calculator->calculate('10', null)?->value);
        self::assertSame('13', $calculator->calculate('11', null)?->value);
        self::assertSame('18446744073709551615', $calculator->calculate('16769767339735956013', null)?->value);
    }

    public function testExpectedSizeFailsClosedWhenSafetyMarginOverflowsUInt64(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ExpectedBackupSize())->calculate(UInt64Decimal::MAXIMUM, null);
    }

    public function testExpectedSizeDecimalAdditionCoversRightPaddingAndCarryBranches(): void
    {
        $method = new \ReflectionMethod(ExpectedBackupSize::class, 'add');

        self::assertSame('1000', $method->invoke(null, '1', '999'));
        self::assertSame('1000', $method->invoke(null, '999', '1'));
        self::assertSame('0', $method->invoke(null, '0', '0'));
    }

    public function testUseCasesDelegateToTheQueueTransactionPort(): void
    {
        $store = new RecordingQueueStore();
        $promotion = new ShadowPromotion(
            self::ID,
            str_repeat('d', 16),
            new DateTimeImmutable('2026-07-12T15:00:00Z'),
            '{}',
            hash('sha256', '{}', true),
        );
        self::assertSame(self::ID, (new PromoteEligibleShadowDecision($store))->execute($promotion));

        $claim = new ClaimNextBackupCommand(str_repeat('w', 16), new DateTimeImmutable('2026-07-12T15:01:00Z'));
        self::assertSame(self::ID, (new ClaimNextBackup($store))->execute($claim)?->id);

        $finalize = new FinalizeClaimedBackupCommand(
            self::ID,
            str_repeat('t', 16),
            1,
            'succeeded',
            new DateTimeImmutable('2026-07-12T15:02:00Z'),
        );
        self::assertTrue((new FinalizeClaimedBackup($store))->execute($finalize));
        self::assertSame([$promotion, $claim, $finalize], $store->commands);
    }

    public function testQueueDtosRejectInvalidIdentifiersTimesStatesAndBounds(): void
    {
        foreach ([
            static fn () => new ShadowPromotion('bad', self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), '{}', hash('sha256', '{}', true)),
            static fn () => new ShadowPromotion(self::ID, self::ID, new DateTimeImmutable('2026-07-12T17:00:00+02:00'), '{}', hash('sha256', '{}', true)),
            static fn () => new ShadowPromotion(self::ID, self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), '{', hash('sha256', '{', true)),
            static fn () => new ShadowPromotion(self::ID, self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), '{}', 'short'),
            static fn () => new ShadowPromotion(self::ID, self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), '{}', str_repeat('h', 32)),
            static fn () => new ClaimNextBackupCommand('bad', new DateTimeImmutable('2026-07-12T15:00:00Z')),
            static fn () => new ClaimNextBackupCommand(self::ID, new DateTimeImmutable('2026-07-12T17:00:00+02:00')),
            static fn () => new ClaimNextBackupCommand(self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), 0),
            static fn () => new ClaimNextBackupCommand(self::ID, new DateTimeImmutable('2026-07-12T15:00:00Z'), 3601),
            static fn () => new ClaimedBackupRequest('bad', self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'leased'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 0, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'leased'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T17:02:00+02:00'), self::ID, self::ID, '1', 'leased'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '01', 'leased'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'pending'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'running'),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'leased', self::ID),
            static fn () => new ClaimedBackupRequest(self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'), self::ID, self::ID, '1', 'running', 'bad'),
            static fn () => new FinalizeClaimedBackupCommand('bad', self::ID, 1, 'failed', new DateTimeImmutable('2026-07-12T15:00:00Z')),
            static fn () => new FinalizeClaimedBackupCommand(self::ID, self::ID, 0, 'failed', new DateTimeImmutable('2026-07-12T15:00:00Z')),
            static fn () => new FinalizeClaimedBackupCommand(self::ID, self::ID, 1, 'running', new DateTimeImmutable('2026-07-12T15:00:00Z')),
            static fn () => new FinalizeClaimedBackupCommand(self::ID, self::ID, 1, 'failed', new DateTimeImmutable('2026-07-12T17:00:00+02:00')),
            static fn () => new FinalizeClaimedBackupCommand(self::ID, self::ID, 1, 'failed', new DateTimeImmutable('2026-07-12T15:00:00Z'), 'Bad detail'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid queue DTO accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        foreach (['starting', 'running', 'reconcile_required'] as $activeState) {
            $claim = new ClaimedBackupRequest(
                self::ID, self::ID, 1, new DateTimeImmutable('2026-07-12T15:02:00Z'),
                self::ID, self::ID, '1', $activeState, str_repeat('r', 16),
            );
            self::assertSame(str_repeat('r', 16), $claim->runId);
        }

        foreach (['succeeded', 'failed', 'cancelled', 'unknown'] as $terminalState) {
            $command = new FinalizeClaimedBackupCommand(
                self::ID,
                self::ID,
                1,
                $terminalState,
                new DateTimeImmutable('2026-07-12T15:00:00Z'),
                'completed',
            );
            self::assertSame($terminalState, $command->terminalState);
        }
    }
}

final class RecordingQueueStore implements BackupQueueStore
{
    /** @var list<object> */
    public array $commands = [];
    public bool $claimable = true;

    public function promote(ShadowPromotion $promotion): string
    {
        $this->commands[] = $promotion;

        return $promotion->requestId;
    }

    public function claim(ClaimNextBackupCommand $command): ?ClaimedBackupRequest
    {
        $this->commands[] = $command;
        if (!$this->claimable) {
            return null;
        }

        return new ClaimedBackupRequest(
            BackupQueueApplicationTest::ID,
            str_repeat('t', 16),
            1,
            $command->now->modify('+120 seconds'),
            str_repeat('n', 16),
            str_repeat('b', 16),
            '100',
            'leased',
        );
    }

    public function finalize(FinalizeClaimedBackupCommand $command): bool
    {
        $this->commands[] = $command;

        return true;
    }
}
