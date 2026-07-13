<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class ConnectionCommandFactory
{
    public function __construct(private SecurityIdentifierGenerator $ids)
    {
    }

    public function fromRequest(Request $request, ConfigurationCommandType $type, ?string $connectionId = null, ?string $endpointId = null): ConfigurationCommand
    {
        $key = $request->headers->get('Idempotency-Key');
        if (!is_string($key)) {
            throw new InvalidArgumentException('An idempotency key is required.');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidArgumentException('The command body must be an object.');
        }
        /** @var array<string, mixed> $payload */
        $payload = $decoded;
        $allowed = match ($type) {
            ConfigurationCommandType::ConnectionUpdate => ['expectedRevision','displayName'],
            ConfigurationCommandType::ConnectionDisable, ConfigurationCommandType::EndpointDisable => ['expectedRevision'],
            default => throw new InvalidArgumentException('The connection command type is invalid.'),
        };
        if (array_diff(array_keys($payload), $allowed) !== []) {
            throw new InvalidArgumentException('The command body contains an unknown field.');
        }
        foreach ($allowed as $required) {
            if (!array_key_exists($required, $payload)) {
                throw new InvalidArgumentException('The command body is incomplete.');
            }
        }
        $revision = $payload['expectedRevision'];
        if (!is_int($revision) || $revision < 0) {
            throw new InvalidArgumentException('An expected revision is required.');
        }
        unset($payload['expectedRevision']);
        if (null !== $endpointId) {
            $payload['endpointId'] = $this->uuid($endpointId);
        }
        $subject = null === $connectionId ? $this->ids->generate() : $this->uuid($connectionId);
        $correlation = $request->headers->get('X-Correlation-ID');
        return new ConfigurationCommand($type, $subject, $revision, $key,
            is_string($correlation) ? $this->uuid($correlation) : $this->ids->generate(), $payload);
    }

    private function uuid(string $value): string
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)) {
            throw new InvalidArgumentException('A canonical UUID is required.');
        }
        return hex2bin(str_replace('-', '', $value)) ?: throw new InvalidArgumentException('A canonical UUID is required.');
    }
}
