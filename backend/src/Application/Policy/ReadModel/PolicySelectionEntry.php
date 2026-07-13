<?php

declare(strict_types=1);

namespace App\Application\Policy\ReadModel;

use App\Application\Inventory\ReadModel\ReadModelIdentifier;
use InvalidArgumentException;

final readonly class PolicySelectionEntry
{
    private const array STATUSES = ['active' => true, 'disabled' => true];
    private const array KINDS = ['assignment' => true, 'guest_override' => true];
    private const array SCOPES = [
        'global' => true, 'connection' => true, 'cluster' => true, 'node' => true, 'guest' => true,
    ];

    public function __construct(
        public string $id,
        public int $revision,
        public string $status,
        public string $kind,
        public string $scope,
        public ?string $connectionId,
        public ?string $clusterId,
        public ?string $nodeId,
        public ?string $guestId,
        public ?string $subjectName,
        public ?string $selectionValue,
        public ?string $mode,
        public ?string $compression,
        public ?PolicyRetention $desiredRetention,
        public ?string $disabledAt,
    ) {
        new ReadModelIdentifier($id);
        foreach ([$connectionId, $clusterId, $nodeId, $guestId] as $identifier) {
            if (null !== $identifier) {
                new ReadModelIdentifier($identifier);
            }
        }
        if ($revision < 1) {
            throw new InvalidArgumentException('The policy selection projection is invalid.');
        }
        if (!isset(self::STATUSES[$status]) || !isset(self::KINDS[$kind]) || !isset(self::SCOPES[$scope])) {
            throw new InvalidArgumentException('The policy selection projection is invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'revision' => $this->revision,
            'status' => $this->status,
            'kind' => $this->kind,
            'scope' => $this->scope,
            'connectionId' => $this->connectionId,
            'clusterId' => $this->clusterId,
            'nodeId' => $this->nodeId,
            'guestId' => $this->guestId,
            'subjectName' => $this->subjectName,
            'selectionValue' => $this->selectionValue,
            'mode' => $this->mode,
            'compression' => $this->compression,
            'desiredRetention' => $this->desiredRetention?->toArray(),
            'disabledAt' => $this->disabledAt,
        ];
    }
}
