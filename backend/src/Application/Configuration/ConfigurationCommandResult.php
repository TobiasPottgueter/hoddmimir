<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use InvalidArgumentException;

final readonly class ConfigurationCommandResult
{
    /** @param list<string> $blockers */
    public function __construct(
        public ConfigurationCommandStatus $status,
        public ?int $revision,
        public array $blockers = [],
    ) {
        if (isset(self::REVISION_RESULTS[$status->value])) {
            if (null === $revision || [] !== $blockers) {
                throw new InvalidArgumentException('The configuration command result is invalid.');
            }
        } elseif (null !== $revision || [] === $blockers) {
            throw new InvalidArgumentException('The configuration command result is invalid.');
        }
        foreach ($blockers as $blocker) {
            if (!self::validBlocker($blocker)) {
                throw new InvalidArgumentException('The configuration command blocker is invalid.');
            }
        }
    }

    private const array REVISION_RESULTS = ['applied' => true, 'replayed' => true, 'conflict' => true];
    private const array BLOCKER_CHARACTERS = [
        'a'=>1,'b'=>1,'c'=>1,'d'=>1,'e'=>1,'f'=>1,'g'=>1,'h'=>1,'i'=>1,'j'=>1,'k'=>1,'l'=>1,'m'=>1,
        'n'=>1,'o'=>1,'p'=>1,'q'=>1,'r'=>1,'s'=>1,'t'=>1,'u'=>1,'v'=>1,'w'=>1,'x'=>1,'y'=>1,'z'=>1,
        '0'=>1,'1'=>1,'2'=>1,'3'=>1,'4'=>1,'5'=>1,'6'=>1,'7'=>1,'8'=>1,'9'=>1,'.'=>1,'_'=>1,'-'=>1,
    ];
    private const array BLOCKER_PUNCTUATION = ['.'=>1,'_'=>1,'-'=>1];

    private static function validBlocker(string $value): bool
    {
        if (strlen($value) < 1 || strlen($value) > 64) return false;
        foreach (str_split($value) as $index => $character) {
            if (!isset(self::BLOCKER_CHARACTERS[$character]) || (0 === $index && isset(self::BLOCKER_PUNCTUATION[$character]))) return false;
        }
        return true;
    }

    public static function blocked(string ...$blockers): self
    {
        return new self(ConfigurationCommandStatus::Blocked, null, array_values($blockers));
    }

    public static function denied(): self
    {
        return new self(ConfigurationCommandStatus::Denied, null, ['permission_denied']);
    }
}
