<?php

declare(strict_types=1);

namespace App\Application\Security\Audit;

interface AuditEventStore
{
    public function append(SecurityAuditEvent $event): void;
}
