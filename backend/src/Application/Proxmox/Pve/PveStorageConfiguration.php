<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class PveStorageConfiguration
{
    /** @var null|list<string> */
    public ?array $nodeAllowlist;

    /** @param null|list<string> $nodeAllowlist */
    public function __construct(
        public string $storageId,
        public string $storageType,
        public PveStorageContentSet $content,
        ?array $nodeAllowlist,
        public bool $disabled,
        public bool $shared,
        public ?PvePbsStorageMapping $pbsMapping,
    ) {
        if (!PveStorageIdValidator::isValid($storageId)) {
            throw new InvalidArgumentException('The storage ID does not match the PVE storage ID grammar.');
        }

        if ('' === $storageType) {
            throw new InvalidArgumentException('The storage type must not be empty.');
        }

        if (null !== $nodeAllowlist) {
            if ([] === $nodeAllowlist) {
                throw new InvalidArgumentException('A node allowlist must not be empty.');
            }

            $nodes = [];
            foreach ($nodeAllowlist as $node) {
                if ('' === $node) {
                    throw new InvalidArgumentException('A storage node name must not be empty.');
                }

                $nodes[$node] = true;
            }

            $nodeAllowlist = array_keys($nodes);
            sort($nodeAllowlist, SORT_STRING);
        }

        $this->nodeAllowlist = $nodeAllowlist;
    }

    public function supportsBackup(): bool
    {
        return $this->content->contains('backup');
    }

    public function isExpectedOn(string $node): bool
    {
        if (!$this->supportsBackup() || $this->disabled) {
            return false;
        }

        if (null === $this->nodeAllowlist) {
            return true;
        }

        foreach ($this->nodeAllowlist as $allowedNode) {
            if ($allowedNode === $node) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *     storage_id: string,
     *     storage_type: string,
     *     content: list<string>,
     *     nodes: null|list<string>,
     *     disabled: bool,
     *     shared: bool,
     *     pbs: null|array{server: string, port: int, datastore: string, namespace: ?string}
     * }
     */
    public function signature(): array
    {
        return [
            'storage_id' => $this->storageId,
            'storage_type' => $this->storageType,
            'content' => $this->content->tokens,
            'nodes' => $this->nodeAllowlist,
            'disabled' => $this->disabled,
            'shared' => $this->shared,
            'pbs' => $this->pbsMapping?->signature(),
        ];
    }
}
