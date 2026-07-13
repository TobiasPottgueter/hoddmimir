<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

use App\Application\Inventory\ReadModel\PageRequest;
use InvalidArgumentException;

final readonly class UserListQuery
{
    public function __construct(public PageRequest $page, public ?string $search = null, public ?bool $enabled = null)
    {
        if (null !== $search && (\trim($search) !== $search || '' === $search || \strlen($search) > 190)) {
            throw new InvalidArgumentException('The user search is invalid.');
        }
    }

    public function context(): string
    {
        return \App\Application\Inventory\ReadModel\PageCursor::context('admin-users-v1', $this->search ?? '', null === $this->enabled ? '' : ($this->enabled ? '1' : '0'));
    }
}
