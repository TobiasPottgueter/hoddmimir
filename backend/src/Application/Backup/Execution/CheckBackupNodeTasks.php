<?php

declare(strict_types=1);

namespace App\Application\Backup\Execution;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveTaskQuery;

/** No owner/guest filter: manual and externally scheduled backups occupy the node too. */
final readonly class CheckBackupNodeTasks
{
    public function __construct(private PveBackupClientProvider $clients)
    {
    }

    /** @param list<string> $nodes */
    public function blocker(string $requestId, array $nodes): ?string
    {
        if ([] === $nodes) {
            return 'remote_tasks_unavailable';
        }
        try {
            $client = $this->clients->forRequest($requestId);
            foreach (array_unique($nodes) as $node) {
                $page = $client->taskPage($node, PveTaskQuery::active());
                if (!$page->isComplete()) {
                    return 'remote_tasks_unavailable';
                }
                if ([] !== $page->tasks) {
                    return 'remote_backup_running';
                }
                if (0 !== $page->rawRowCount || !$page->isShort()) {
                    return 'remote_tasks_unavailable';
                }
            }
        } catch (PveBackupApiFailure) {
            return 'remote_tasks_unavailable';
        }

        return null;
    }
}
