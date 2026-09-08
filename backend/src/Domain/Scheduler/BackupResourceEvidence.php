<?php

declare(strict_types=1);

namespace App\Domain\Scheduler;

final readonly class BackupResourceEvidence
{
    public function __construct(
        public bool $connectionEnabled,
        public bool $clusterActive,
        public bool $guestActive,
        public ?bool $guestTemplate,
        public bool $nodeActive,
        public bool $nodeOnline,
        public bool $policyEnabled,
        public bool $targetEnabled,
        public bool $storageSupportsBackup,
        public ?bool $storageDisabled,
        public bool $storageActive,
        public bool $nodeStorageEnabled,
        public bool $nodeStorageActive,
        public bool $executorAuthorized,
        public bool $targetNodeAllowed,
        public bool $selectionIncluded,
        public bool $selectionExcluded,
        public bool $activeRequestAbsent,
        public bool $capacityEvidencePresent,
    ) {}
}
