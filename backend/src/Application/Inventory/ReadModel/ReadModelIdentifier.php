<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

use InvalidArgumentException;

final readonly class ReadModelIdentifier
{
    private const array HEXADECIMAL = [
        '0' => true, '1' => true, '2' => true, '3' => true,
        '4' => true, '5' => true, '6' => true, '7' => true,
        '8' => true, '9' => true, 'a' => true, 'b' => true,
        'c' => true, 'd' => true, 'e' => true, 'f' => true,
    ];

    public function __construct(public string $value)
    {
        if (36 !== strlen($value)) {
            throw new InvalidArgumentException('The inventory identifier must be a canonical lowercase UUID.');
        }
        for ($index = 0; $index < 36; ++$index) {
            if (8 === $index || 13 === $index || 18 === $index || 23 === $index) {
                if ('-' !== $value[$index]) {
                    throw new InvalidArgumentException('The inventory identifier must be a canonical lowercase UUID.');
                }
                continue;
            }
            if (!isset(self::HEXADECIMAL[$value[$index]])) {
                throw new InvalidArgumentException('The inventory identifier must be a canonical lowercase UUID.');
            }
        }
    }

    public function binary(): string
    {
        $compact = '';
        for ($index = 0; $index < 36; ++$index) {
            if ('-' !== $this->value[$index]) {
                $compact .= $this->value[$index];
            }
        }
        return pack('H*', $compact);
    }
}
