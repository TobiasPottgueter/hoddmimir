<?php

declare(strict_types=1);

namespace App\Domain\Policy;

use InvalidArgumentException;

final readonly class FailureNotificationRecipients
{
    /** @var list<string> */
    public array $addresses;

    /** @param list<string> $addresses */
    public function __construct(array $addresses)
    {
        if (\count($addresses) > 32) {
            throw new InvalidArgumentException('Too many backup failure notification recipients.');
        }

        $seen = [];
        foreach ($addresses as $address) {
            if ($address !== \trim($address) || \strlen($address) > 254 || false === \filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('A backup failure notification recipient is invalid.');
            }
            $key = \strtolower($address);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Backup failure notification recipients must be unique.');
            }
            $seen[$key] = true;
        }

        \usort($addresses, static fn (string $left, string $right): int => \strcasecmp($left, $right));
        $this->addresses = $addresses;
    }
}
