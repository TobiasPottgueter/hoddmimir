<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use App\Domain\Shared\Clock;

/** Bounded GET-only job and task observation for one already selected PVE endpoint. */
final readonly class ReadPveBackupInventory
{
    public function __construct(
        private Clock $clock,
        private PveBackupInventoryLimits $limits = new PveBackupInventoryLimits(),
    ) {
    }

    /**
     * @param list<string> $nodes Authoritative topology nodes for the selected endpoint.
     *
     * A monitoring-window planner may supply a cursor-derived window. The
     * clock-derived window is the bounded initial/default read only.
     */
    public function read(
        PveReadClient $client,
        array $nodes,
        ?PveTaskArchiveWindow $archiveWindow = null,
    ): PveBackupInventorySnapshot {
        $window = $archiveWindow
            ?? PveTaskArchiveWindow::endingAt($this->clock->now(), $this->limits->archiveWindowSeconds);
        if ($window->widthSeconds() > $this->limits->archiveWindowSeconds) {
            throw new \InvalidArgumentException('The PVE task archive window exceeds the configured limit.');
        }
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

        $nodes = $this->validatedNodes($nodes, $issues);
        if ([] === $nodes && [] !== $issues) {
            return new PveBackupInventorySnapshot($jobs, [], $issues, $window, [], 0, 0);
        }

        $tasksByUpid = [];
        $taskStreams = [];
        $requestCount = 0;
        $rawRowCount = 0;
        $globalLimitReached = false;

        foreach ($nodes as $node) {
            foreach ([PveTaskSource::Active, PveTaskSource::Archive] as $source) {
                if ($globalLimitReached) {
                    $taskStreams[] = new PveTaskStreamScanResult(
                        $node,
                        $source,
                        PveTaskStreamScanStatus::NotScannedLimit,
                        0,
                        0,
                    );
                    continue;
                }

                [$stream, $globalLimitReached] = $this->readStream(
                    $client,
                    $node,
                    $source,
                    $window,
                    $tasksByUpid,
                    $issues,
                    $requestCount,
                    $rawRowCount,
                );
                $taskStreams[] = $stream;
            }
        }

        ksort($tasksByUpid, SORT_STRING);

        return new PveBackupInventorySnapshot(
            $jobs,
            array_values($tasksByUpid),
            $issues,
            $window,
            $taskStreams,
            $requestCount,
            $rawRowCount,
        );
    }

    /**
     * @param array<string, PveBackupTask>     $tasksByUpid
     * @param list<PveBackupInventoryIssue>   $issues
     * @return array{PveTaskStreamScanResult, bool}
     */
    private function readStream(
        PveReadClient $client,
        string $node,
        PveTaskSource $source,
        PveTaskArchiveWindow $window,
        array &$tasksByUpid,
        array &$issues,
        int &$requestCount,
        int &$rawRowCount,
    ): array {
        $query = PveTaskSource::Active === $source
            ? PveTaskQuery::active(limit: $this->limits->pageSize)
            : PveTaskQuery::archive($window->since, $window->until, limit: $this->limits->pageSize);
        $streamRequests = 0;
        $streamRawRows = 0;
        $hadPageIssues = false;

        for ($pageNumber = 0; $pageNumber < $this->limits->pageCap($source); ++$pageNumber) {
            $limitIssue = $this->nextGlobalLimitIssue($node, $source, $requestCount, $rawRowCount);
            if (null !== $limitIssue) {
                $issues[] = $limitIssue;

                return [new PveTaskStreamScanResult(
                    $node,
                    $source,
                    0 === $streamRequests
                        ? PveTaskStreamScanStatus::NotScannedLimit
                        : PveTaskStreamScanStatus::Partial,
                    $streamRequests,
                    $streamRawRows,
                ), true];
            }

            try {
                ++$requestCount;
                ++$streamRequests;
                $page = $client->backupTaskPage($node, $query);
            } catch (PveReadFailure) {
                $issues[] = new PveBackupInventoryIssue(
                    PveBackupInventoryIssueCode::TaskStreamReadFailed,
                    sprintf('/nodes/%s/tasks', $node),
                    '/data',
                    $node,
                    $source->value,
                );

                return [new PveTaskStreamScanResult(
                    $node,
                    $source,
                    1 === $streamRequests
                        ? PveTaskStreamScanStatus::Failed
                        : PveTaskStreamScanStatus::Partial,
                    $streamRequests,
                    $streamRawRows,
                ), false];
            }

            $rawRowCount += $page->rawRowCount;
            $streamRawRows += $page->rawRowCount;
            foreach ($page->issues as $issue) {
                $issues[] = $issue;
                $hadPageIssues = true;
            }

            foreach ($page->tasks as $task) {
                $known = $tasksByUpid[$task->upid->raw] ?? null;
                if (null === $known) {
                    if (count($tasksByUpid) >= $this->limits->distinctTaskLimit) {
                        $issues[] = new PveBackupInventoryIssue(
                            PveBackupInventoryIssueCode::DistinctTaskLimitReached,
                            sprintf('/nodes/%s/tasks', $node),
                            '/limits/distinct-tasks',
                            $node,
                            $source->value,
                        );

                        return [new PveTaskStreamScanResult(
                            $node,
                            $source,
                            PveTaskStreamScanStatus::Partial,
                            $streamRequests,
                            $streamRawRows,
                        ), true];
                    }
                    $tasksByUpid[$task->upid->raw] = $task;
                    continue;
                }

                $enriched = $known->enrich($task);
                if (null === $enriched) {
                    $issues[] = new PveBackupInventoryIssue(
                        PveBackupInventoryIssueCode::ConflictingDuplicateTask,
                        sprintf('/nodes/%s/tasks', $node),
                        '/data/*/upid',
                        $node,
                        $task->upid->raw,
                    );
                    $hadPageIssues = true;
                    continue;
                }
                $tasksByUpid[$task->upid->raw] = $enriched;
            }

            if ($page->isShort()) {
                return [new PveTaskStreamScanResult(
                    $node,
                    $source,
                    $hadPageIssues ? PveTaskStreamScanStatus::Partial : PveTaskStreamScanStatus::Complete,
                    $streamRequests,
                    $streamRawRows,
                ), false];
            }

            $query = $query->nextPage();
        }

        $issues[] = new PveBackupInventoryIssue(
            PveBackupInventoryIssueCode::PageCapReached,
            sprintf('/nodes/%s/tasks', $node),
            '/pagination',
            $node,
            $source->value,
        );

        return [new PveTaskStreamScanResult(
            $node,
            $source,
            PveTaskStreamScanStatus::Partial,
            $streamRequests,
            $streamRawRows,
        ), false];
    }

    private function nextGlobalLimitIssue(
        string $node,
        PveTaskSource $source,
        int $requestCount,
        int $rawRowCount,
    ): ?PveBackupInventoryIssue {
        if ($requestCount >= $this->limits->requestLimit) {
            return new PveBackupInventoryIssue(
                PveBackupInventoryIssueCode::RequestLimitReached,
                sprintf('/nodes/%s/tasks', $node),
                '/limits/requests',
                $node,
                $source->value,
            );
        }
        if ($rawRowCount > $this->limits->rawRowLimit - $this->limits->pageSize) {
            return new PveBackupInventoryIssue(
                PveBackupInventoryIssueCode::RawRowLimitReached,
                sprintf('/nodes/%s/tasks', $node),
                '/limits/raw-rows',
                $node,
                $source->value,
            );
        }

        return null;
    }

    /**
     * @param list<mixed>                     $nodes
     * @param list<PveBackupInventoryIssue>  $issues
     * @return list<string>
     */
    private function validatedNodes(array $nodes, array &$issues): array
    {
        if (count($nodes) > $this->limits->nodeLimit) {
            $issues[] = new PveBackupInventoryIssue(
                PveBackupInventoryIssueCode::NodeLimitReached,
                '/nodes/{node}/tasks',
                '/limits/nodes',
            );

            return [];
        }

        $seen = [];
        foreach ($nodes as $node) {
            if (!is_string($node) || !PveTaskNodeNameValidator::isValid($node) || isset($seen[$node])) {
                $issues[] = new PveBackupInventoryIssue(
                    PveBackupInventoryIssueCode::InvalidNode,
                    '/nodes/{node}/tasks',
                    '/node',
                    is_string($node) ? $node : null,
                );
                continue;
            }
            $seen[$node] = true;
        }
        if ([] !== $issues) {
            return [];
        }

        $nodes = array_keys($seen);
        usort($nodes, static fn (string $left, string $right): int => strcmp($left, $right));

        return $nodes;
    }
}
