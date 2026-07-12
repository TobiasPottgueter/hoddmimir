<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

final readonly class PbsTaskScanner
{
    public function __construct(
        private PbsTaskPageSource $source,
        private PbsTasksAndJobsLimits $limits = new PbsTasksAndJobsLimits(),
    ) {}

    public function scan(string $node, PbsTaskWindow $window): PbsTaskScanSnapshot
    {
        if ($window->until - $window->since > $this->limits->maximumHistoryWindowSeconds) {
            throw new InvalidArgumentException('The PBS task history window exceeds the configured limit.');
        }
        $tasks = [];
        $issues = [];
        $streams = [];
        foreach (PbsTaskFilterFamily::cases() as $family) {
            foreach ([PbsTaskPass::Running, PbsTaskPass::History] as $pass) {
                [$streamTasks, $issue, $stream] = $this->scanStream($node, $family, $pass, $window);
                foreach ($streamTasks as $task) {
                    $tasks[] = $task;
                }
                $streams[] = $stream;
                if (null !== $issue) {
                    $issues[] = $issue;
                }
            }
        }
        return new PbsTaskScanSnapshot($window, $tasks, $issues, $streams);
    }

    /** @return array{list<PbsTaskObservation>, ?PbsTaskScanIssue, PbsTaskStreamResult} */
    private function scanStream(
        string $node,
        PbsTaskFilterFamily $family,
        PbsTaskPass $pass,
        PbsTaskWindow $window,
    ): array {
        $start = 0;
        $pages = 0;
        $rawRows = 0;
        $tasks = [];
        $fingerprints = [];
        while ($pages < $this->limits->maximumPagesPerStream) {
            $remainingRows = $this->limits->maximumRowsPerStream - $rawRows;
            if (0 === $remainingRows) {
                return $this->partial(
                    $tasks, $family, $pass, PbsTaskScanIssueCode::RowCapExceeded, $pages, $rawRows,
                );
            }
            if ($this->limits->pageSize < $remainingRows) {
                $pageLimit = $this->limits->pageSize;
            } else {
                $pageLimit = $remainingRows;
            }
            try {
                $page = $this->source->page($node, new PbsTaskListQuery(
                    $family,
                    $pass,
                    $start,
                    $pageLimit,
                    PbsTaskPass::History === $pass ? $window : null,
                ));
            } catch (PbsReadFailure) {
                return $this->readFailed($tasks, $family, $pass, $pages, $rawRows);
            }
            ++$pages;
            $count = $page->rawRowCount;
            if (0 === $count) {
                if (null !== $page->total && $page->total > $start) {
                    return $this->partial(
                        $tasks, $family, $pass, PbsTaskScanIssueCode::NoProgress, $pages, $rawRows,
                    );
                }
                return $this->complete($tasks, $family, $pass, $pages, $rawRows);
            }
            $fingerprint = $page->rawFingerprint;
            if (isset($fingerprints[$fingerprint])) {
                return $this->partial(
                    $tasks, $family, $pass, PbsTaskScanIssueCode::RepeatedPage, $pages, $rawRows,
                );
            }
            $fingerprints[$fingerprint] = true;
            if ($count > $remainingRows) {
                return $this->partial(
                    $tasks, $family, $pass, PbsTaskScanIssueCode::RowCapExceeded, $pages, $rawRows,
                );
            }
            $rawRows += $count;
            foreach ($page->tasks as $task) {
                if ($family->allows($task->upid->workerType)) {
                    $tasks[] = $task;
                }
            }
            $start += $count;
            if (null !== $page->total) {
                $hasMore = $page->total > $start;
            } else {
                $hasMore = $count === $pageLimit;
            }
            if (false === $hasMore) {
                return $this->complete($tasks, $family, $pass, $pages, $rawRows);
            }
        }
        return $this->partial(
            $tasks, $family, $pass, PbsTaskScanIssueCode::PageCapExceeded, $pages, $rawRows,
        );
    }

    /** @param list<PbsTaskObservation> $tasks
     *  @return array{list<PbsTaskObservation>, null, PbsTaskStreamResult}
     */
    private function complete(
        array $tasks,
        PbsTaskFilterFamily $family,
        PbsTaskPass $pass,
        int $pages,
        int $rawRows,
    ): array {
        return [$tasks, null, new PbsTaskStreamResult(
            $family,
            $pass,
            PbsTaskStreamStatus::Complete,
            $pages,
            $rawRows,
            count($tasks),
            false,
            false,
            null,
        )];
    }

    /** @param list<PbsTaskObservation> $tasks
     *  @return array{list<PbsTaskObservation>, PbsTaskScanIssue, PbsTaskStreamResult}
     */
    private function partial(
        array $tasks,
        PbsTaskFilterFamily $family,
        PbsTaskPass $pass,
        PbsTaskScanIssueCode $code,
        int $pages,
        int $rawRows,
    ): array {
        $issue = new PbsTaskScanIssue($family, $pass, $code);
        return [$tasks, $issue, new PbsTaskStreamResult(
            $family,
            $pass,
            PbsTaskStreamStatus::Partial,
            $pages,
            $rawRows,
            count($tasks),
            true,
            PbsTaskPass::History === $pass,
            $code,
        )];
    }

    /** @param list<PbsTaskObservation> $tasks
     *  @return array{list<PbsTaskObservation>, PbsTaskScanIssue, PbsTaskStreamResult}
     */
    private function readFailed(
        array $tasks,
        PbsTaskFilterFamily $family,
        PbsTaskPass $pass,
        int $pages,
        int $rawRows,
    ): array {
        $issue = new PbsTaskScanIssue($family, $pass, PbsTaskScanIssueCode::ReadFailed);
        $failed = 0 === $pages;
        return [$tasks, $issue, new PbsTaskStreamResult(
            $family,
            $pass,
            $failed ? PbsTaskStreamStatus::Failed : PbsTaskStreamStatus::Partial,
            $pages,
            $rawRows,
            count($tasks),
            true,
            PbsTaskPass::History === $pass,
            PbsTaskScanIssueCode::ReadFailed,
        )];
    }
}
