<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsNamespace
{
    public function __construct(public string $value)
    {
        if ('' === $value) {
            return;
        }
        $segments = explode('/', $value);
        if (strlen($value) > 256 || count($segments) > 8) {
            throw new InvalidArgumentException('The PBS namespace is invalid.');
        }
        foreach ($segments as $segment) {
            $length = strlen($segment);
            if (0 === $length
                || 1 !== strspn($segment[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_')
                || $length !== strspn(
                    $segment,
                    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.-',
                )) {
                throw new InvalidArgumentException('The PBS namespace is invalid.');
            }
        }
    }

    public static function root(): self
    {
        return new self('');
    }

    public function isRoot(): bool
    {
        return '' === $this->value;
    }

    public function depth(): int
    {
        return $this->isRoot() ? 0 : substr_count($this->value, '/') + 1;
    }

    public function parent(): ?self
    {
        if ($this->isRoot()) {
            return null;
        }
        $segments = explode('/', $this->value);
        array_pop($segments);
        $parent = '';
        foreach ($segments as $segment) {
            $parent = '' === $parent ? $segment : $parent.'/'.$segment;
        }
        return new self($parent);
    }
}
