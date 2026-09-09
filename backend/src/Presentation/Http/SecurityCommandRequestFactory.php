<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Administration\SecurityCommand;
use App\Application\Administration\SecurityCommandBuilder;
use App\Application\Administration\SecurityCommandType;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class SecurityCommandRequestFactory
{
    public function __construct(private SecurityCommandBuilder $builder)
    {
    }

    public function fromRequest(Request $request, SecurityCommandType $type, ?string $id = null): SecurityCommand
    {
        $body = $this->body($request);
        $allowed = match ($type) {
            SecurityCommandType::UserCreate => ['expectedRevision', 'username', 'displayName', 'password', 'roles'],
            SecurityCommandType::UserUpdate => ['expectedRevision', 'displayName', 'password'],
            SecurityCommandType::UserDisable => ['expectedRevision'],
            SecurityCommandType::UserRolesReplace => ['expectedRevision', 'roles'],
        };
        $this->exactKeys($body, $allowed);
        $expectedRevision = $body['expectedRevision'] ?? null;
        if (!is_int($expectedRevision) || $expectedRevision < 0) {
            throw new InvalidArgumentException('The expected revision is invalid.');
        }
        $password = $body['password'] ?? null;
        if (null !== $password && !is_string($password)) {
            throw new InvalidArgumentException('The password is invalid.');
        }
        unset($body['expectedRevision'], $body['password']);
        $idempotency = $request->headers->get('Idempotency-Key');
        if (!is_string($idempotency)) {
            throw new InvalidArgumentException('An idempotency key is required.');
        }
        $correlation = $request->headers->get('X-Correlation-ID');
        return $this->builder->build(
            $type,
            null === $id ? null : (new ReadModelIdentifier($id))->binary(),
            $expectedRevision,
            $body,
            $idempotency,
            is_string($correlation) ? (new ReadModelIdentifier($correlation))->binary() : null,
            $password,
        );
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('The JSON request body is invalid.');
        }
        if (!is_array($body) || array_is_list($body)) {
            throw new InvalidArgumentException('The JSON request body is invalid.');
        }
        foreach (array_keys($body) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('The JSON request body is invalid.');
            }
        }
        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $allowed
     */
    private function exactKeys(array $body, array $allowed): void
    {
        foreach (array_keys($body) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('The request contains an unsupported field.');
            }
        }
        foreach ($allowed as $key) {
            if ('password' !== $key && !array_key_exists($key, $body)) {
                throw new InvalidArgumentException('The request is missing a field.');
            }
        }
        if (in_array('password', $allowed, true) && in_array('username', $allowed, true) && !array_key_exists('password', $body)) {
            throw new InvalidArgumentException('The request is missing a password.');
        }
    }
}
