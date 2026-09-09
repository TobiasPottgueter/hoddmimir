<?php

declare(strict_types=1);

namespace App\Application\Administration;

use App\Application\Security\Auth\PasswordHash;
use App\Application\Security\PlaintextSecret;
use InvalidArgumentException;
use JsonException;

final readonly class SecurityCommand
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public SecurityCommandType $type,
        public string $subjectId,
        public int $expectedRevision,
        public array $payload,
        public string $idempotencyKey,
        public string $correlationId,
        public ?PlaintextSecret $password = null,
        public ?PasswordHash $passwordHash = null,
    ) {
        if (16 !== \strlen($subjectId) || 16 !== \strlen($correlationId) || $expectedRevision < 0
            || 1 !== \preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $idempotencyKey)
            || (null === $password) !== (null === $passwordHash)) {
            throw new InvalidArgumentException('The security command is invalid.');
        }
    }

    public function payloadHash(): string
    {
        try {
            $json = \json_encode([
                'type' => $this->type->value,
                'subjectId' => \bin2hex($this->subjectId),
                'expectedRevision' => $this->expectedRevision,
                'payload' => $this->canonical($this->payload),
                'passwordProvided' => null !== $this->password,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new InvalidArgumentException('The security command payload is invalid.');
        }

        return \hash('sha256', $json, true);
    }

    private function canonical(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (\array_is_list($value)) {
            return \array_map($this->canonical(...), $value);
        }
        \ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }
        return $value;
    }
}
