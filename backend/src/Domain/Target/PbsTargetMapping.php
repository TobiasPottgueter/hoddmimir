<?php

declare(strict_types=1);

namespace App\Domain\Target;

use InvalidArgumentException;

final readonly class PbsTargetMapping
{
    public function __construct(
        public string $connectionId,
        public string $datastoreId,
        public ?string $namespaceId,
    ) {
        foreach ([$connectionId, $datastoreId, $namespaceId] as $id) {
            if (null !== $id && 16 !== strlen($id)) {
                throw new InvalidArgumentException('A PBS target mapping contains an invalid identifier.');
            }
        }
    }
}
