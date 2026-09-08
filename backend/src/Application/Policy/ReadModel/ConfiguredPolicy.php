<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

use App\Application\Configuration\Policy\PolicyActivationBlockerCode;
use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;

final readonly class ConfiguredPolicy
{
    private const array STATUSES = ['draft' => true, 'enabled' => true, 'disabled' => true];

    /**
     * @param list<string> $failureNotificationRecipients
     * @param list<PolicyActivationBlockerCode> $blockers
     */
    public function __construct(
        public string $id,
        public int $revision,
        public string $status,
        public string $displayName,
        public string $connectionId,
        public string $connectionName,
        public string $clusterId,
        public string $clusterName,
        public ?string $targetId,
        public ?string $targetName,
        public ?int $priority,
        public ?string $mode,
        public ?string $compression,
        public ?int $maximumAgeSeconds,
        public ?string $bytesWrittenThreshold,
        public ?int $cooldownSeconds,
        public ?string $schedule,
        public ?PolicyRetention $desiredRetention,
        public bool $retentionExecutionEnabled,
        public ?string $disabledAt,
        public array $blockers,
        public array $failureNotificationRecipients = [],
        public ?string $effectiveMode = null,
        public ?string $effectiveCompression = null,
        public ?PolicyRetention $effectiveRetention = null,
    ) {
        foreach ([$id, $connectionId, $clusterId] as $identifier) {
            new ReadModelIdentifier($identifier);
        }
        if (null !== $targetId) {
            new ReadModelIdentifier($targetId);
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('The configured policy projection is invalid.');
        }
        if (!isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('The configured policy projection is invalid.');
        }
        if ('' === $displayName || '' === $connectionName || '' === $clusterName) {
            throw new InvalidArgumentException('The configured policy projection is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            'status' => $this->status,
            'displayName' => $this->displayName,
            'connectionId' => $this->connectionId,
            'connectionName' => $this->connectionName,
            'clusterId' => $this->clusterId,
            'clusterName' => $this->clusterName,
            'targetId' => $this->targetId,
            'targetName' => $this->targetName,
            'priority' => $this->priority,
            'effectiveMode' => $this->effectiveMode ?? $this->mode,
            'effectiveCompression' => $this->effectiveCompression ?? $this->compression,
            'effectiveRetention' => ($this->effectiveRetention ?? $this->desiredRetention)?->toArray(),
            'mode' => $this->mode,
            'compression' => $this->compression,
            'maximumAgeSeconds' => $this->maximumAgeSeconds,
            'bytesWrittenThreshold' => $this->bytesWrittenThreshold,
            'cooldownSeconds' => $this->cooldownSeconds,
            'schedule' => $this->schedule,
            'desiredRetention' => $this->desiredRetention?->toArray(),
            'retentionExecutionEnabled' => $this->retentionExecutionEnabled,
            'failureNotificationRecipients' => $this->failureNotificationRecipients,
            'disabledAt' => $this->disabledAt,
            'canEnable' => 'enabled' !== $this->status && [] === $this->blockers,
            'blockers' => array_column($this->blockers, 'value'),
        ];
    }
}
