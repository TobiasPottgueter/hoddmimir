<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveStorageContentSet
{
    /** @var list<string> */
    public array $tokens;

    /** @param list<string> $tokens */
    public function __construct(array $tokens)
    {
        if ([] === $tokens) {
            throw new InvalidArgumentException('A storage content set must not be empty.');
        }

        $normalized = [];
        foreach ($tokens as $token) {
            if ('' === $token) {
                throw new InvalidArgumentException('A storage content token must not be empty.');
            }

            $normalized[$token] = true;
        }

        $tokens = array_keys($normalized);
        sort($tokens, SORT_STRING);
        $this->tokens = $tokens;
    }

    public function contains(string $token): bool
    {
        foreach ($this->tokens as $candidate) {
            if ($candidate === $token) {
                return true;
            }
        }

        return false;
    }

    public function equals(self $other): bool
    {
        return $this->tokens === $other->tokens;
    }
}
