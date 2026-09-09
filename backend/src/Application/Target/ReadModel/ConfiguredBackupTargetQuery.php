<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use InvalidArgumentException;

final readonly class ConfiguredBackupTargetQuery
{
    public function __construct(
        public PageRequest $page,
        public ?string $search = null,
        public ?bool $enabled = null,
    ) {
        if (null !== $search && ('' === $search || \strlen($search) > 190 || \str_contains($search, "\0"))) {
            throw new InvalidArgumentException('The configured backup-target search is invalid.');
        }
        $this->page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context(
            'configured-backup-targets-v1',
            $this->search ?? '',
            null === $this->enabled ? '' : ($this->enabled ? '1' : '0'),
        );
    }
}
