<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

final readonly class AdministrationRole implements AdministrationReadItem
{
    /** @param list<string> $permissions */
    public function __construct(public string $id, public string $name, public string $displayName, public array $permissions)
    {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'displayName' => $this->displayName, 'permissions' => $this->permissions];
    }
}
