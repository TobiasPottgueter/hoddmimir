<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

use function preg_match;
use function str_pad;
use function strlen;
use function trim;

/** Filters refer to the run's request, never the guest's current placement. */
final readonly class BackupRunQuery
{
    public ?DateTimeImmutable $startedFrom;
    public ?DateTimeImmutable $startedBefore;

    public function __construct(
        public PageRequest $page,
        public ?BackupRunState $state = null,
        public ?ReadModelIdentifier $guestId = null,
        public ?ReadModelIdentifier $nodeId = null,
        public ?ReadModelIdentifier $targetId = null,
        public ?int $vmid = null,
        public ?string $search = null,
        ?string $startedFrom = null,
        ?string $startedBefore = null,
    ) {
        if (null !== $vmid && ($vmid < 1 || $vmid > 2147483647)) {
            throw new InvalidArgumentException('The VMID filter is invalid.');
        }
        if (null !== $search && ('' === trim($search) || strlen($search) > 190 || 1 !== preg_match('/\A[^\x00-\x1f\x7f]+\z/uD', $search))) {
            throw new InvalidArgumentException('The guest name filter is invalid.');
        }
        $this->startedFrom = self::timestamp($startedFrom);
        $this->startedBefore = self::timestamp($startedBefore);
        if (null !== $this->startedFrom && null !== $this->startedBefore && $this->startedFrom >= $this->startedBefore) {
            throw new InvalidArgumentException('The start interval must have positive width.');
        }
        $page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
        // Resource cursors carry arbitrary text; runs specifically require a canonical timestamp.
        if (null !== $page->cursor) self::timestamp($page->cursor->first);
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            'backup-runs-v2',
            null === $this->state ? '' : $this->state->value,
            null === $this->guestId ? '' : $this->guestId->value,
            null === $this->nodeId ? '' : $this->nodeId->value,
            null === $this->targetId ? '' : $this->targetId->value,
            null === $this->vmid ? '' : (string) $this->vmid,
            $this->search ?? '',
            $this->startedFrom?->format('Y-m-d\TH:i:s.u\Z') ?? '',
            $this->startedBefore?->format('Y-m-d\TH:i:s.u\Z') ?? '',
        );
    }

    private static function timestamp(?string $value): ?DateTimeImmutable
    {
        if (null === $value) return null;
        if (1 !== preg_match('/\A([1-9][0-9]{3}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?Z\z/D', $value, $matches)) {
            throw new InvalidArgumentException('A UTC timestamp with Z is required.');
        }
        $canonical = $matches[1].'.'.str_pad($matches[2] ?? '', 6, '0').'Z';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $canonical, new DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s.u\Z') !== $canonical) {
            throw new InvalidArgumentException('The UTC timestamp is invalid.');
        }
        return $date;
    }
}
