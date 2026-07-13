<?php

declare(strict_types=1);

namespace App\Presentation\Http;

use App\Application\Configuration\ConfigurationCommand;
use App\Application\Configuration\ConfigurationCommandType;
use App\Application\Security\Auth\SecurityIdentifierGenerator;
use App\Domain\Policy\FailureNotificationRecipients;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\Request;

final readonly class ConfigurationCommandFactory
{
    private const array COMMON = ['expectedRevision'];
    private const array TARGET = ['expectedRevision','displayName','connectionId','clusterId','storageId','minimumFreeBytes','fixedParallelLimit','pbsConnectionId','pbsDatastoreId','pbsNamespaceId','allowedNodeIds'];
    private const array POLICY = ['expectedRevision','displayName','connectionId','clusterId','targetId','priority','backupMode','compression','maximumAgeSeconds','bytesWrittenThreshold','cooldownSeconds','schedule','legacyMaxfiles','keepAll','keepLast','keepHourly','keepDaily','keepWeekly','keepMonthly','keepYearly','retentionExecutionEnabled','failureNotificationRecipients'];
    private const array BULK = ['expectedRevision','entries'];

    public function __construct(private SecurityIdentifierGenerator $ids)
    {
    }

    public function fromRequest(Request $request, ConfigurationCommandType $type, ?string $routeId = null): ConfigurationCommand
    {
        $idempotencyKey = $request->headers->get('Idempotency-Key');
        if (!is_string($idempotencyKey)) throw new InvalidArgumentException('An idempotency key is required.');
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) throw new InvalidArgumentException('The command body must be an object.');
        /** @var array<string, mixed> $decoded */
        $allowed = match ($type) {
            ConfigurationCommandType::TargetCreate, ConfigurationCommandType::TargetUpdate => self::TARGET,
            ConfigurationCommandType::PolicyCreate, ConfigurationCommandType::PolicyUpdate => self::POLICY,
            ConfigurationCommandType::SelectionUpsert, ConfigurationCommandType::SelectionDisable,
            ConfigurationCommandType::GuestOverrideUpsert, ConfigurationCommandType::GuestOverrideDisable => self::BULK,
            default => self::COMMON,
        };
        foreach (array_keys($decoded) as $key) {
            if (!in_array($key, $allowed, true)) throw new InvalidArgumentException('The command body contains an unknown field.');
        }
        if (in_array($type, [ConfigurationCommandType::TargetCreate, ConfigurationCommandType::TargetUpdate,
            ConfigurationCommandType::PolicyCreate, ConfigurationCommandType::PolicyUpdate], true)) {
            foreach ($allowed as $required) {
                if (!array_key_exists($required, $decoded)) throw new InvalidArgumentException('The command body is incomplete.');
            }
        }
        $revision = $decoded['expectedRevision'] ?? null;
        if (!is_int($revision)) throw new InvalidArgumentException('An expected revision is required.');
        unset($decoded['expectedRevision']);
        $payload = $this->binaryIdentifiers($decoded, $type);
        $subject = null === $routeId ? $this->ids->generate() : $this->uuid($routeId);
        $correlation = $request->headers->get('X-Correlation-ID');
        return new ConfigurationCommand($type, $subject, $revision, $idempotencyKey,
            is_string($correlation) ? $this->uuid($correlation) : $this->ids->generate(), $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function binaryIdentifiers(array $payload, ConfigurationCommandType $type): array
    {
        foreach (['connectionId','clusterId','storageId','pbsConnectionId','pbsDatastoreId','pbsNamespaceId','targetId'] as $key) {
            if (array_key_exists($key, $payload) && null !== $payload[$key]) {
                if (!is_string($payload[$key])) throw new InvalidArgumentException('A command identifier is invalid.');
                $payload[$key] = $this->uuid($payload[$key]);
            }
        }
        if (array_key_exists('allowedNodeIds', $payload)) {
            if (!is_array($payload['allowedNodeIds']) || !array_is_list($payload['allowedNodeIds'])) throw new InvalidArgumentException('Allowed nodes are invalid.');
            $payload['allowedNodeIds'] = array_map(fn (mixed $id): string => is_string($id) ? $this->uuid($id) : throw new InvalidArgumentException('Allowed nodes are invalid.'), $payload['allowedNodeIds']);
        }
        if (array_key_exists('failureNotificationRecipients', $payload)) {
            $recipients = $payload['failureNotificationRecipients'];
            if (!is_array($recipients) || !array_is_list($recipients)) {
                throw new InvalidArgumentException('Failure notification recipients are invalid.');
            }
            foreach ($recipients as $address) {
                if (!is_string($address)) {
                    throw new InvalidArgumentException('Failure notification recipients are invalid.');
                }
            }
            /** @var list<string> $recipients */
            $payload['failureNotificationRecipients'] = (new FailureNotificationRecipients($recipients))->addresses;
        }
        if (array_key_exists('entries', $payload)) {
            if (!is_array($payload['entries']) || !array_is_list($payload['entries'])) throw new InvalidArgumentException('Bulk entries are invalid.');
            $payload['entries'] = array_map(function (mixed $entry) use ($type): array {
                if (!is_array($entry) || array_is_list($entry)) throw new InvalidArgumentException('A bulk entry is invalid.');
                $normalized = [];
                foreach ($entry as $key => $value) {
                    if (!is_string($key)) throw new InvalidArgumentException('A bulk entry is invalid.');
                    if (!in_array($key, self::entryFields($type), true)) throw new InvalidArgumentException('A bulk entry contains an unknown field.');
                    $normalized[$key] = $value;
                }
                foreach (['id','subjectConnectionId','subjectClusterId','nodeId','guestId'] as $key) {
                    if (array_key_exists($key, $normalized) && null !== $normalized[$key]) {
                        if (!is_string($normalized[$key])) throw new InvalidArgumentException('A bulk identifier is invalid.');
                        $normalized[$key] = $this->uuid($normalized[$key]);
                    }
                }
                return $normalized;
            }, $payload['entries']);
        }
        return $payload;
    }

    /** @return list<string> */
    private static function entryFields(ConfigurationCommandType $type): array
    {
        return match ($type) {
            ConfigurationCommandType::SelectionUpsert => ['id','scope','subjectConnectionId','subjectClusterId','nodeId','guestId','selectionValue'],
            ConfigurationCommandType::SelectionDisable, ConfigurationCommandType::GuestOverrideDisable => ['id'],
            ConfigurationCommandType::GuestOverrideUpsert => ['id','guestId','backupMode','compression','legacyMaxfiles','keepAll','keepLast','keepHourly','keepDaily','keepWeekly','keepMonthly','keepYearly'],
            default => [],
        };
    }

    private function uuid(string $value): string
    {
        if (1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $value)) throw new InvalidArgumentException('A canonical UUID is required.');
        $binary = hex2bin(str_replace('-', '', $value));
        if (false === $binary) throw new InvalidArgumentException('A canonical UUID is required.');
        return $binary;
    }
}
