<?php

declare(strict_types=1);

namespace App\Application\Backup\Operations;

use App\Application\Inventory\ReadModel\PageRequest;

interface OperationsReadModel
{
    public function dashboard(bool $includeAudit): OperationsDashboard;
    public function queue(PageRequest $page, ?BackupRequestState $state): OperationsPage;
    public function runs(PageRequest $page, ?BackupRunState $state): OperationsPage;
    /** @return array<string, mixed>|null */
    public function run(string $id): ?array;
    public function requestEvents(string $requestId, PageRequest $page): OperationsPage;
    public function runEvents(string $runId, PageRequest $page): OperationsPage;
    public function runLogs(string $runId, PageRequest $page): OperationsPage;
    public function notifications(PageRequest $page, ?string $kind): OperationsPage;
    public function notificationHealth(): OperationsNotificationHealth;
}
