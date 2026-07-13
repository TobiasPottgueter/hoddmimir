<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

final readonly class AdministrationUser implements AdministrationReadItem
{
    /** @param list<string> $roles */
    public function __construct(
        public string $id,
        public string $username,
        public string $displayName,
        public bool $enabled,
        public int $revision,
        public array $roles,
        public string $createdAt,
        public string $updatedAt,
        public ?string $disabledAt,
        public ?string $lastLoginAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id, 'username' => $this->username, 'displayName' => $this->displayName,
            'enabled' => $this->enabled, 'revision' => $this->revision, 'roles' => $this->roles,
            'createdAt' => $this->createdAt, 'updatedAt' => $this->updatedAt,
            'disabledAt' => $this->disabledAt, 'lastLoginAt' => $this->lastLoginAt,
        ];
    }
}
