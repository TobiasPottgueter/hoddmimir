<?php

declare(strict_types=1);

namespace App\Application\Administration\ReadModel;

use App\Application\Inventory\ReadModel\PageRequest;

interface AdministrationReadModel
{
    public function users(UserListQuery $query): AdministrationPage;
    public function roles(PageRequest $page): AdministrationPage;
    public function audit(AuditListQuery $query): AdministrationPage;
    public function auditEvent(string $id): ?AdministrationAuditEvent;
}
