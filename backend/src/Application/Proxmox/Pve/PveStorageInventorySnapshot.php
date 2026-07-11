<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class PveStorageInventorySnapshot
{
    /** @var list<PveNodeStorageObservation> */
    public array $observations;

    /** @var list<PveStorageIssue> */
    public array $issues;

    /**
     * @param list<PveNodeStorageObservation> $observations
     * @param list<PveStorageIssue>           $issues
     */
    public function __construct(
        public ?PveStorageConfigurationSet $startConfiguration,
        public ?PveStorageConfigurationSet $endConfiguration,
        array $observations,
        array $issues,
    ) {
        $this->observations = $observations;
        $this->issues = $issues;
    }

    public function isAuthoritative(): bool
    {
        return [] === $this->issues
            && null !== $this->startConfiguration
            && null !== $this->endConfiguration
            && $this->startConfiguration->isContractValid()
            && $this->endConfiguration->isContractValid()
            && $this->startConfiguration->globalDigest === $this->endConfiguration->globalDigest
            && $this->startConfiguration->hasSameVisibleDefinitions($this->endConfiguration);
    }
}
