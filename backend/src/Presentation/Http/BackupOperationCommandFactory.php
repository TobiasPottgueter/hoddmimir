<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Backup\Operations\BackupOperationCommand;
use App\Application\Backup\Operations\BackupOperationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

final readonly class BackupOperationCommandFactory
{
    private const string NIL = "\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0\0";

    public function __construct(private SecurityIdentifierGenerator $ids) {}

    public function manual(Request $request): BackupOperationCommand
    {
        $body = $this->body($request, ['guestId', 'policyId', 'expectedRevision']);
        return new BackupOperationCommand(
            BackupOperationCommandType::ManualRequest,
            $this->ids->generate(),
            $this->uuid($body['policyId'] ?? null),
            $this->uuid($body['guestId'] ?? null),
            $this->revision($body['expectedRevision'] ?? null),
            $this->idempotencyKey($request),
            $this->correlation($request),
        );
    }

    public function cancel(Request $request, string $requestId): BackupOperationCommand
    {
        $body = $this->body($request, ['expectedRevision']);
        return new BackupOperationCommand(
            BackupOperationCommandType::CancelRequest,
            $this->uuid($requestId), self::NIL, self::NIL,
            $this->revision($body['expectedRevision'] ?? null),
            $this->idempotencyKey($request),
            $this->correlation($request),
        );
    }

    /** @param list<string> $allowed
     * @return array<string, mixed>
     */
    private function body(Request $request, array $allowed): array
    {
        $body = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($body) || array_is_list($body) || [] !== array_diff(array_keys($body), $allowed)
            || [] !== array_diff($allowed, array_keys($body))) {
            throw new InvalidArgumentException('The backup-operation body is invalid.');
        }
        /** @var array<string, mixed> $body */
        return $body;
    }

    private function idempotencyKey(Request $request): string
    {
        $value = $request->headers->get('Idempotency-Key');
        if (!is_string($value)) throw new InvalidArgumentException('An idempotency key is required.');
        return $value;
    }
    private function correlation(Request $request): string
    {
        $value = $request->headers->get('X-Correlation-ID');
        return is_string($value) ? $this->uuid($value) : $this->ids->generate();
    }
    private function revision(mixed $value): int { if (!is_int($value) || $value < 0) throw new InvalidArgumentException('An expected revision is required.'); return $value; }
    private function uuid(mixed $value): string
    {
        if (!is_string($value) || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)) throw new InvalidArgumentException('A canonical UUID is required.');
        return hex2bin(str_replace('-', '', $value)) ?: throw new InvalidArgumentException('A canonical UUID is required.');
    }
}
