<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

final readonly class ReadPveBackupInventory
{
    public const PAGE_SIZE = 100;
    public const PAGE_CAP = 100;

    /**
     * @param list<string> $nodes
     */
    public function read(
        PveReadClient $client,
        array $nodes,
        int $archiveSince,
        int $archiveUntil,
    ): PveBackupInventorySnapshot {
        $issues = [];

        try {
            $jobs = $client->backupJobs();
        } catch (PveReadFailure) {
            $jobs = new PveBackupJobInventory(
                PveBackupJobCapabilities::forMajor($client->version()->major),
                [],
                [new PveBackupInventoryIssue(
                    PveBackupInventoryIssueCode::BackupJobReadFailed,
                    '/cluster/backup',
                    '/data',
                )],
            );
        }

        $tasksByUpid = [];
        $seenNodes = [];
        foreach ($nodes as $node) {
            if (isset($seenNodes[$node])) {
                continue;
            }
            $seenNodes[$node] = true;

            if (!$this->validNode($node)) {
                $issues[] = new PveBackupInventoryIssue(
                    PveBackupInventoryIssueCode::InvalidNode,
                    '/nodes/{node}/tasks',
                    '/node',
                    $node,
                );
                continue;
            }

            $this->readStream(
                $client,
                $node,
                PveTaskQuery::active(limit: self::PAGE_SIZE),
                $tasksByUpid,
                $issues,
            );
            $this->readStream(
                $client,
                $node,
                PveTaskQuery::archive($archiveSince, $archiveUntil, limit: self::PAGE_SIZE),
                $tasksByUpid,
                $issues,
            );
        }

        return new PveBackupInventorySnapshot($jobs, array_values($tasksByUpid), $issues);
    }

    /**
     * @param array<string, PveBackupTask>    $tasksByUpid
     * @param list<PveBackupInventoryIssue> $issues
     */
    private function readStream(
        PveReadClient $client,
        string $node,
        PveTaskQuery $query,
        array &$tasksByUpid,
        array &$issues,
    ): void {
        for ($pageNumber = 0; $pageNumber < self::PAGE_CAP; ++$pageNumber) {
            try {
                $page = $client->backupTaskPage($node, $query);
            } catch (PveReadFailure) {
                $issues[] = new PveBackupInventoryIssue(
                    PveBackupInventoryIssueCode::TaskStreamReadFailed,
                    sprintf('/nodes/%s/tasks', $node),
                    '/data',
                    $node,
                    $query->source->value,
                );
                return;
            }

            foreach ($page->issues as $issue) {
                $issues[] = $issue;
            }

            foreach ($page->tasks as $task) {
                $known = $tasksByUpid[$task->upid->raw] ?? null;
                if (null === $known) {
                    $tasksByUpid[$task->upid->raw] = $task;
                    continue;
                }

                if ($known->signature() !== $task->signature()) {
                    $issues[] = new PveBackupInventoryIssue(
                        PveBackupInventoryIssueCode::ConflictingDuplicateTask,
                        sprintf('/nodes/%s/tasks', $node),
                        '/data/*/upid',
                        $node,
                        $task->upid->raw,
                    );
                }
            }

            if ($page->isShort()) {
                return;
            }

            $query = $query->nextPage();
        }

        $issues[] = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::PageCapReached,
            sprintf('/nodes/%s/tasks', $node),
            '/pagination',
            $node,
            $query->source->value,
        );
    }

    private function validNode(string $node): bool
    {
        return PveTaskNodeNameValidator::isValid($node);
    }
}
