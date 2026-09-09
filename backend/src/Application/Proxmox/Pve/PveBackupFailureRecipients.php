<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveBackupFailureRecipients
{
    /** @var non-empty-list<string> */
    public array $addresses;

    /** @param list<string> $addresses */
    public function __construct(array $addresses)
    {
        if ([] === $addresses) {
            throw new InvalidArgumentException('At least one PVE backup failure notification recipient is required.');
        }
        if (\count($addresses) > 32) {
            throw new InvalidArgumentException('Too many PVE backup failure notification recipients.');
        }

        $seen = [];
        foreach ($addresses as $address) {
            if ($address !== \trim($address)) {
                throw new InvalidArgumentException('A PVE backup failure notification recipient is invalid.');
            }
            if (\strlen($address) > 254) {
                throw new InvalidArgumentException('A PVE backup failure notification recipient is invalid.');
            }
            if (false === \filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('A PVE backup failure notification recipient is invalid.');
            }

            $comparisonKey = \strtolower($address);
            if (isset($seen[$comparisonKey])) {
                throw new InvalidArgumentException('PVE backup failure notification recipients must be unique.');
            }
            $seen[$comparisonKey] = true;
        }

        $this->addresses = $addresses;
    }

    public function parameterValue(): string
    {
        return \implode(',', $this->addresses);
    }
}
