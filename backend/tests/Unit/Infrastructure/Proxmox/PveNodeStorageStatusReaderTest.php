<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Application\Proxmox\Pve\PveStorageCapacityState;
use App\Application\Proxmox\Pve\PveStorageIssue;
use App\Application\Proxmox\Pve\PveStorageIssueCode;
use App\Infrastructure\Proxmox\PveNodeStorageStatusReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PveNodeStorageStatusReaderTest extends TestCase
{
    public function testItWhitelistsTypedActiveAndInactiveObservations(): void
    {
        $set = (new PveNodeStorageStatusReader())->read('node.test', [
            (object) [
                'storage' => 'z-active',
                'type' => 'future-plugin',
                'content' => ' future, backup,backup ',
                'enabled' => 1,
                'active' => true,
                'shared' => 0,
                'total' => 100,
                'used' => 30,
                'avail' => 60,
                'secret-like-extra' => 'ignored',
            ],
            [
                'storage' => 'a-inactive',
                'type' => 'pbs',
                'content' => 'backup',
                'enabled' => true,
                'active' => false,
                'shared' => true,
                'total' => 0,
                'used' => 0,
                'avail' => 0,
            ],
            [
                'storage' => 'm-unavailable',
                'type' => 'dir',
                'content' => 'backup',
                'enabled' => true,
                'active' => false,
                'shared' => false,
            ],
        ]);

        self::assertSame('node.test', $set->node);
        self::assertSame(['a-inactive', 'm-unavailable', 'z-active'], array_map(
            static fn ($status): string => $status->storageId,
            $set->statuses,
        ));
        self::assertSame([], $set->issues);

        $inactive = $set->statuses[0];
        self::assertSame(PveStorageCapacityState::Unavailable, $inactive->capacityState);
        self::assertNull($inactive->capacity);

        $active = $set->statuses[2];
        self::assertSame('future-plugin', $active->storageType);
        self::assertSame(['backup', 'future'], $active->content->tokens);
        self::assertSame(PveStorageCapacityState::Fresh, $active->capacityState);
        self::assertNotNull($active->capacity);
        self::assertSame(100, $active->capacity->totalBytes);
        self::assertSame(30, $active->capacity->usedBytes);
        self::assertSame(60, $active->capacity->availableBytes);
    }

    #[DataProvider('invalidTopLevelProvider')]
    public function testItRejectsAnInvalidNodeOrTopLevelResponse(string $node, mixed $data): void
    {
        try {
            (new PveNodeStorageStatusReader())->read($node, $data);
            self::fail('Expected a typed response failure.');
        } catch (PveReadFailure $failure) {
            self::assertSame(PveReadFailureCode::InvalidResponse, $failure->failureCode);
        }
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidTopLevelProvider(): iterable
    {
        yield 'empty node' => ['', []];
        yield 'node nul' => ["node\0.test", []];
        yield 'node escape' => ["node\x1B.test", []];
        yield 'node newline' => ["node\n.test", []];
        yield 'node delete' => ["node\x7F.test", []];
        yield 'node unicode' => ['nöde.test', []];
        yield 'map' => ['node.test', ['data' => []]];
        yield 'object' => ['node.test', (object) []];
        yield 'string' => ['node.test', 'invalid'];
    }

    public function testMalformedDuplicateAndInvalidCoreRowsRemainPartial(): void
    {
        $valid = $this->row('valid');
        $set = (new PveNodeStorageStatusReader())->read('node.test', [
            'not-a-row',
            $valid,
            $valid,
            array_diff_key($this->row('missing-storage'), ['storage' => true]),
            array_replace($this->row('bad-storage'), ['storage' => '']),
            array_diff_key($this->row('missing-type'), ['type' => true]),
            array_replace($this->row('bad-type'), ['type' => 7]),
            array_replace($this->row('storage-trailing-dot'), ['storage' => 'backup.']),
            array_replace($this->row('storage-control'), ['storage' => "backup\x1Bstore"]),
            array_replace($this->row('storage-unicode'), ['storage' => 'bäckup']),
            array_replace($this->row('type-delete'), ['type' => "dir\x7F"]),
            array_replace($this->row('type-unicode'), ['type' => 'dír']),
            array_diff_key($this->row('missing-content'), ['content' => true]),
            array_replace($this->row('bad-content'), ['content' => 'backup,,iso']),
            array_replace($this->row('wrong-content'), ['content' => 7]),
            array_replace($this->row('empty-content'), ['content' => '']),
            array_replace($this->row('unicode-content'), ['content' => 'bäckup']),
        ]);

        self::assertSame(['valid'], array_map(static fn ($status): string => $status->storageId, $set->statuses));
        self::assertContains(PveStorageIssueCode::DuplicateStorage, $this->codes($set->issues));
        self::assertContains(PveStorageIssueCode::MissingRequiredField, $this->codes($set->issues));
        self::assertContains(PveStorageIssueCode::InvalidField, $this->codes($set->issues));
        self::assertContains('/data/13/content', $this->fields($set->issues));
        self::assertContains('/data/16/content', $this->fields($set->issues));
    }

    #[DataProvider('invalidBooleanProvider')]
    public function testAllStatusBooleansAreRequiredAndStrict(string $field, bool $missing): void
    {
        $row = $this->row('backup');
        if ($missing) {
            unset($row[$field]);
        } else {
            $row[$field] = '1';
        }

        $set = (new PveNodeStorageStatusReader())->read('node.test', [$row]);
        self::assertSame([], $set->statuses);
        self::assertSame(
            [$missing ? PveStorageIssueCode::MissingRequiredField : PveStorageIssueCode::InvalidField],
            $this->codes($set->issues),
        );
        self::assertSame(['/data/0/'.$field], $this->fields($set->issues));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function invalidBooleanProvider(): iterable
    {
        foreach (['enabled', 'active', 'shared'] as $field) {
            yield $field.' missing' => [$field, true];
            yield $field.' invalid' => [$field, false];
        }
    }

    public function testReturnedDisabledStatusIsAConflictAndNeverHasFreshCapacity(): void
    {
        $set = (new PveNodeStorageStatusReader())->read('node.test', [[
            'storage' => 'disabled',
            'type' => 'dir',
            'content' => 'backup',
            'enabled' => false,
            'active' => true,
            'shared' => false,
            'total' => 10,
            'used' => 2,
            'avail' => 8,
        ]]);

        self::assertCount(1, $set->statuses);
        self::assertSame(PveStorageCapacityState::Unavailable, $set->statuses[0]->capacityState);
        self::assertNull($set->statuses[0]->capacity);
        self::assertSame([
            PveStorageIssueCode::ConfigurationStatusConflict,
            PveStorageIssueCode::InvalidCapacity,
        ], $this->codes($set->issues));
    }

    public function testOfficialDottedStorageIdIsAccepted(): void
    {
        $set = (new PveNodeStorageStatusReader())->read('node.test', [$this->row('Backup.store')]);

        self::assertSame([], $set->issues);
        self::assertSame('Backup.store', $set->statuses[0]->storageId);
    }

    public function testInactiveNonZeroOrMalformedCapacityIsUnavailableAndPartial(): void
    {
        $set = (new PveNodeStorageStatusReader())->read('node.test', [
            array_replace($this->row('nonzero'), ['active' => false, 'total' => 10, 'used' => 0, 'avail' => 10]),
            array_replace($this->row('partial'), ['active' => false, 'total' => 0, 'used' => 0]),
        ]);

        self::assertCount(2, $set->statuses);
        self::assertSame(PveStorageCapacityState::Unavailable, $set->statuses[0]->capacityState);
        self::assertSame(PveStorageCapacityState::Unavailable, $set->statuses[1]->capacityState);
        self::assertSame([
            PveStorageIssueCode::InvalidCapacity,
            PveStorageIssueCode::InvalidCapacity,
        ], $this->codes($set->issues));
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidActiveCapacityProvider')]
    public function testActiveCapacityMustBeAnAtomicNonNegativeBoundedTriple(array $overrides): void
    {
        $set = (new PveNodeStorageStatusReader())->read(
            'node.test',
            [array_replace($this->row('backup'), $overrides)],
        );

        self::assertCount(1, $set->statuses);
        self::assertSame(PveStorageCapacityState::Invalid, $set->statuses[0]->capacityState);
        self::assertNull($set->statuses[0]->capacity);
        self::assertSame([PveStorageIssueCode::InvalidCapacity], $this->codes($set->issues));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidActiveCapacityProvider(): iterable
    {
        yield 'missing total' => [['total' => null]];
        yield 'wrong total type' => [['total' => '100']];
        yield 'negative total' => [['total' => -1]];
        yield 'missing used' => [['used' => null]];
        yield 'wrong used type' => [['used' => '20']];
        yield 'negative used' => [['used' => -1]];
        yield 'missing available' => [['avail' => null]];
        yield 'wrong available type' => [['avail' => '70']];
        yield 'negative available' => [['avail' => -1]];
        yield 'used exceeds total' => [['total' => 100, 'used' => 101, 'avail' => 0]];
        yield 'available exceeds total' => [['total' => 100, 'used' => 0, 'avail' => 101]];
    }

    /** @return array<string, mixed> */
    private function row(string $storage): array
    {
        return [
            'storage' => $storage,
            'type' => 'dir',
            'content' => 'backup',
            'enabled' => true,
            'active' => true,
            'shared' => false,
            'total' => 100,
            'used' => 20,
            'avail' => 70,
        ];
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<PveStorageIssueCode>
     */
    private function codes(array $issues): array
    {
        return array_map(static fn (PveStorageIssue $issue): PveStorageIssueCode => $issue->code, $issues);
    }

    /**
     * @param list<PveStorageIssue> $issues
     *
     * @return list<string>
     */
    private function fields(array $issues): array
    {
        return array_map(static fn (PveStorageIssue $issue): string => $issue->field, $issues);
    }
}
