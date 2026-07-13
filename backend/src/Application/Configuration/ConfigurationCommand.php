<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Application\Security\PlaintextSecret;
use InvalidArgumentException;

final readonly class ConfigurationCommand
{
    public string $payloadHash;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public ConfigurationCommandType $type,
        public string $subjectId,
        public int $expectedRevision,
        public string $idempotencyKey,
        public string $correlationId,
        public array $payload = [],
        public ?PlaintextSecret $secret = null,
    ) {
        if (16 !== strlen($subjectId) || 16 !== strlen($correlationId) || $expectedRevision < 0
            || !self::validIdempotencyKey($idempotencyKey)) {
            throw new InvalidArgumentException('The configuration command envelope is invalid.');
        }
        $canonical = self::canonicalize($payload);
        $encoded = json_encode([
            'type' => $type->value,
            'subject' => bin2hex($subjectId),
            'expectedRevision' => $expectedRevision,
            'payload' => $canonical,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        $this->payloadHash = hash('sha256', $encoded, true);
    }

    private static function validIdempotencyKey(string $value): bool
    {
        if (strlen($value) < 1 || strlen($value) > 128) {
            return false;
        }
        foreach (str_split($value) as $index => $character) {
            if (!isset(self::KEY_CHARACTERS[$character]) || (0 === $index && isset(self::KEY_PUNCTUATION[$character]))) {
                return false;
            }
        }
        return true;
    }

    private const array KEY_CHARACTERS = [
        'A'=>1,'B'=>1,'C'=>1,'D'=>1,'E'=>1,'F'=>1,'G'=>1,'H'=>1,'I'=>1,'J'=>1,'K'=>1,'L'=>1,'M'=>1,
        'N'=>1,'O'=>1,'P'=>1,'Q'=>1,'R'=>1,'S'=>1,'T'=>1,'U'=>1,'V'=>1,'W'=>1,'X'=>1,'Y'=>1,'Z'=>1,
        'a'=>1,'b'=>1,'c'=>1,'d'=>1,'e'=>1,'f'=>1,'g'=>1,'h'=>1,'i'=>1,'j'=>1,'k'=>1,'l'=>1,'m'=>1,
        'n'=>1,'o'=>1,'p'=>1,'q'=>1,'r'=>1,'s'=>1,'t'=>1,'u'=>1,'v'=>1,'w'=>1,'x'=>1,'y'=>1,'z'=>1,
        '0'=>1,'1'=>1,'2'=>1,'3'=>1,'4'=>1,'5'=>1,'6'=>1,'7'=>1,'8'=>1,'9'=>1,'.'=>1,'_'=>1,':'=>1,'-'=>1,
    ];
    private const array KEY_PUNCTUATION = ['.'=>1,'_'=>1,':'=>1,'-'=>1];

    /** @param mixed $value
     *  @return mixed
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::canonicalize($item);
            }
            return $value;
        }
        if (is_string($value) && 16 === strlen($value)) {
            return ['$binary16' => bin2hex($value)];
        }
        if (null !== $value && !is_string($value) && !is_int($value) && !is_bool($value)) {
            throw new InvalidArgumentException('The configuration command payload is invalid.');
        }
        return $value;
    }

    public function boundedEntries(string $key): int
    {
        $entries = $this->payload[$key] ?? null;
        if (!is_array($entries) || !array_is_list($entries) || count($entries) < 1 || count($entries) > 500) {
            throw new InvalidArgumentException('A bounded configuration command requires between 1 and 500 entries.');
        }
        return count($entries);
    }
}
