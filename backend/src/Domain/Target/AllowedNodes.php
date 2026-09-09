<?php

declare(strict_types=1);

namespace App\Domain\Target;

use InvalidArgumentException;

final readonly class AllowedNodes
{
    /** @var list<string> */
    public array $ids;

    /** @param list<string> $ids */
    public function __construct(array $ids)
    {
        $unique = [];
        foreach ($ids as $id) {
            if (16 !== strlen($id)) {
                throw new InvalidArgumentException('An allowed-node ID must contain exactly 16 bytes.');
            }
            $unique[bin2hex($id)] = $id;
        }
        ksort($unique);
        $this->ids = array_values($unique);
    }

    public function empty(): bool
    {
        return [] === $this->ids;
    }
}
