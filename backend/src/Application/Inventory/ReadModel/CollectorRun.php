<?php

declare(strict_types=1);

namespace App\Application\Inventory\ReadModel;

final readonly class CollectorRun
{
    public function __construct(
        public string $id,
        public string $connectionId,
        public string $connectionName,
        public string $product,
        public string $status,
        public bool $authoritative,
        public string $startedAt,
        public ?string $finishedAt,
        public ?string $appliedAt,
        public int $nodesSeen,
        public int $guestsSeen,
        public int $storagesSeen,
        public ?string $errorCode,
    ) {}

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'connectionId' => $this->connectionId,
            'connectionName' => $this->connectionName,
            'product' => $this->product,
            'status' => $this->status,
            'authoritative' => $this->authoritative,
            'startedAt' => $this->startedAt,
            'finishedAt' => $this->finishedAt,
            'appliedAt' => $this->appliedAt,
            'nodesSeen' => $this->nodesSeen,
            'guestsSeen' => $this->guestsSeen,
            'storagesSeen' => $this->storagesSeen,
            'errorCode' => $this->errorCode,
        ];
    }
}
