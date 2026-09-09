<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Scheduler;

use App\Domain\Scheduler\ClassStableFifoOrderer;
use App\Domain\Scheduler\Priority;
use App\Domain\Scheduler\QueueOrderEntry;
use App\Domain\Scheduler\WithinPriorityFairness;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassStableFifoOrdererTest extends TestCase
{
    public function testFifoOrdersClassesThenScheduleThenStableBinaryId(): void
    {
        $entries = [
            $this->entry('d', Priority::BytesWritten, '2026-07-12T10:00:00Z'),
            $this->entry('c', Priority::MaxAge, '2026-07-12T09:00:00Z'),
            $this->entry('b', Priority::MaxAge, '2026-07-12T10:00:00Z'),
            $this->entry('a', Priority::MaxAge, '2026-07-12T10:00:00Z'),
            $this->entry('e', Priority::Manual, '2026-07-12T12:00:00Z'),
            $this->entry('f', Priority::NeverBackedUp, '2026-07-12T08:00:00Z'),
        ];

        self::assertSame(
            ['e', 'f', 'c', 'a', 'b', 'd'],
            $this->labels((new ClassStableFifoOrderer())->fifo($entries)),
        );
    }

    public function testEmptyAndSingleEntryListsRemainValidAndUtcIsNormalized(): void
    {
        $orderer = new ClassStableFifoOrderer();
        self::assertSame([], $orderer->fifo([]));
        self::assertSame([], $orderer->withFairness([], new RecordingReverseFairness()));

        $entry = $this->entry('a', Priority::MaxAge, '2026-07-12T12:00:00+02:00');
        self::assertSame('+00:00', $entry->scheduledAt->format('P'));
        self::assertSame([$entry], $orderer->fifo([$entry]));
    }

    public function testFairnessReceivesFifoEntriesOneClassAtATimeAndCannotCrossClasses(): void
    {
        $fairness = new RecordingReverseFairness();
        $ordered = (new ClassStableFifoOrderer())->withFairness([
            $this->entry('a', Priority::MaxAge, '2026-07-12T09:00:00Z'),
            $this->entry('b', Priority::MaxAge, '2026-07-12T10:00:00Z'),
            $this->entry('c', Priority::Manual, '2026-07-12T11:00:00Z'),
            $this->entry('d', Priority::Manual, '2026-07-12T12:00:00Z'),
        ], $fairness);

        self::assertSame(['d', 'c', 'b', 'a'], $this->labels($ordered));
        self::assertSame([
            [Priority::Manual, ['c', 'd']],
            [Priority::MaxAge, ['a', 'b']],
        ], $fairness->calls);
    }

    public function testDuplicateInputIdsFailClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ClassStableFifoOrderer())->fifo([
            $this->entry('a', Priority::MaxAge, '2026-07-12T09:00:00Z'),
            $this->entry('a', Priority::BytesWritten, '2026-07-12T10:00:00Z'),
        ]);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFairnessResults(): iterable
    {
        yield 'drops entry' => ['drop'];
        yield 'duplicates entry' => ['duplicate'];
        yield 'injects unknown entry' => ['inject'];
        yield 'replaces existing entry' => ['replace'];
        yield 'changes priority class' => ['cross_class'];
    }

    #[DataProvider('invalidFairnessResults')]
    public function testInvalidFairnessOutputFailsClosed(string $mode): void
    {
        $fairness = new InvalidResultFairness($mode);
        $this->expectException(InvalidArgumentException::class);
        (new ClassStableFifoOrderer())->withFairness([
            $this->entry('a', Priority::MaxAge, '2026-07-12T09:00:00Z'),
            $this->entry('b', Priority::MaxAge, '2026-07-12T10:00:00Z'),
        ], $fairness);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => [str_repeat('a', 15)];
        yield 'long' => [str_repeat('a', 17)];
    }

    #[DataProvider('invalidIds')]
    public function testOrderingIdsAreExactlySixteenBytes(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        new QueueOrderEntry($id, Priority::MaxAge, new DateTimeImmutable('2026-07-12T10:00:00Z'));
    }

    private function entry(string $label, Priority $priority, string $scheduledAt): QueueOrderEntry
    {
        return new QueueOrderEntry(str_repeat($label, 16), $priority, new DateTimeImmutable($scheduledAt));
    }

    /**
     * @param list<QueueOrderEntry> $entries
     *
     * @return list<string>
     */
    private function labels(array $entries): array
    {
        return array_map(static fn (QueueOrderEntry $entry): string => $entry->stableId[0], $entries);
    }
}

final class RecordingReverseFairness implements WithinPriorityFairness
{
    /** @var list<array{Priority, list<string>}> */
    public array $calls = [];

    public function reorder(Priority $priority, array $fifoEntries): array
    {
        $this->calls[] = [
            $priority,
            array_map(static fn (QueueOrderEntry $entry): string => $entry->stableId[0], $fifoEntries),
        ];

        return array_reverse($fifoEntries);
    }
}

final readonly class InvalidResultFairness implements WithinPriorityFairness
{
    public function __construct(private string $mode)
    {
    }

    public function reorder(Priority $priority, array $fifoEntries): array
    {
        return match ($this->mode) {
            'drop' => [$fifoEntries[0]],
            'duplicate' => [$fifoEntries[0], $fifoEntries[0]],
            'inject' => [
                $fifoEntries[0],
                new QueueOrderEntry(str_repeat('z', 16), $priority, $fifoEntries[1]->scheduledAt),
            ],
            'replace' => [
                $fifoEntries[0],
                new QueueOrderEntry($fifoEntries[1]->stableId, $priority, $fifoEntries[1]->scheduledAt),
            ],
            'cross_class' => [
                $fifoEntries[0],
                new QueueOrderEntry(
                    $fifoEntries[1]->stableId,
                    Priority::BytesWritten,
                    $fifoEntries[1]->scheduledAt,
                ),
            ],
            default => throw new \LogicException('Unknown test fairness mode.'),
        };
    }
}
