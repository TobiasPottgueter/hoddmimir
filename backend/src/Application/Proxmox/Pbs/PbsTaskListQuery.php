<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTaskListQuery
{
    public function __construct(
        public PbsTaskFilterFamily $family,
        public PbsTaskPass $pass,
        public int $start,
        public int $limit,
        public ?PbsTaskWindow $window,
    ) {
        if ($start < 0 || $limit < 1 || $limit > 1000
            || (PbsTaskPass::History === $pass) !== (null !== $window)) {
            throw new InvalidArgumentException('The PBS task list query is invalid.');
        }
    }
}
