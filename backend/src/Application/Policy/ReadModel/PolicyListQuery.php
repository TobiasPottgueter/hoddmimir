<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

use App\Application\Inventory\ReadModel\PageCursor;
use App\Application\Inventory\ReadModel\PageCursorKind;
use App\Application\Inventory\ReadModel\PageRequest;
use InvalidArgumentException;

final readonly class PolicyListQuery
{
    private const array STATUSES = ['draft' => true, 'enabled' => true, 'disabled' => true];

    public function __construct(
        public PageRequest $page,
        public ?string $search = null,
        public ?string $status = null,
    ) {
        if (null !== $search) {
            if ('' === $search) {
                throw new InvalidArgumentException('The policy search is invalid.');
            }
            if (strlen($search) > 190) {
                throw new InvalidArgumentException('The policy search is invalid.');
            }
            foreach (str_split($search) as $character) {
                if ("\0" === $character) {
                    throw new InvalidArgumentException('The policy search is invalid.');
                }
            }
        }
        if (null !== $status && !isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('The policy status filter is invalid.');
        }
        $page->cursor?->assertContext(PageCursorKind::Resource, $this->cursorContext());
    }

    public function cursorContext(): string
    {
        return PageCursor::context('configured-policies-v1', $this->search ?? '', $this->status ?? '');
    }
}
