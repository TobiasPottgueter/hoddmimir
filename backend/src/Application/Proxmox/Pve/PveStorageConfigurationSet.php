<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveStorageConfigurationSet
{
    /** @var list<PveStorageConfiguration> */
    public array $definitions;

    /** @var list<PveStorageIssue> */
    public array $issues;

    /**
     * @param list<PveStorageConfiguration> $definitions
     * @param list<PveStorageIssue>         $issues
     */
    public function __construct(
        public ?string $globalDigest,
        array $definitions,
        array $issues,
    ) {
        if (null !== $globalDigest && !$this->isVisibleAscii($globalDigest)) {
            throw new InvalidArgumentException('The global storage configuration digest must be visible ASCII.');
        }

        $storageIds = [];
        foreach ($definitions as $definition) {
            if (isset($storageIds[$definition->storageId])) {
                throw new InvalidArgumentException('A storage configuration set must not contain duplicate IDs.');
            }

            $storageIds[$definition->storageId] = true;
        }

        usort(
            $definitions,
            static fn (PveStorageConfiguration $left, PveStorageConfiguration $right): int => $left->storageId <=> $right->storageId,
        );
        $this->definitions = $definitions;
        $this->issues = $issues;
    }

    public function isContractValid(): bool
    {
        return null !== $this->globalDigest && [] !== $this->definitions && [] === $this->issues;
    }

    public function hasSameVisibleDefinitions(self $other): bool
    {
        if (count($this->definitions) !== count($other->definitions)) {
            return false;
        }

        foreach ($this->definitions as $index => $definition) {
            if ($definition->signature() !== $other->definitions[$index]->signature()) {
                return false;
            }
        }

        return true;
    }

    private function isVisibleAscii(string $value): bool
    {
        if ('' === $value) {
            return false;
        }

        for ($index = 0; isset($value[$index]); ++$index) {
            $character = $value[$index];
            if ($character < '!' || $character > '~') {
                return false;
            }
        }

        return true;
    }
}
