<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Backup\Monitoring\AmbiguousSubmissionEvidence;
use App\Application\Backup\Monitoring\AmbiguousSubmissionIdentity;
use App\Application\Backup\Monitoring\AmbiguousSubmissionTaskSource;
use App\Application\Proxmox\Pve\PveBackupClientProvider;
use App\Application\Proxmox\Pve\PveBackupTask;
use App\Application\Proxmox\Pve\PveTaskQuery;
use App\Domain\Shared\Clock;

final readonly class PveAmbiguousSubmissionTaskSource implements AmbiguousSubmissionTaskSource
{
    private const int MAXIMUM_PAGE_CAP = 16;
    private const int LOGICAL_GET_BUDGET_SECONDS = 95;

    public function __construct(
        private PveBackupClientProvider $clients,
        private Clock $clock,
        private int $pageSize = 100,
        private int $activePageCap = 2,
        private int $archivePageCap = 10,
        private int $totalPageCap = 12,
        private int $maximumElapsedSeconds = 1_200,
    ) {
        if ($pageSize < 1 || $pageSize > PveTaskQuery::MAXIMUM_PAGE_SIZE
            || $activePageCap < 1 || $activePageCap > self::MAXIMUM_PAGE_CAP
            || $archivePageCap < 1 || $archivePageCap > self::MAXIMUM_PAGE_CAP
            || $totalPageCap < 1 || $totalPageCap > self::MAXIMUM_PAGE_CAP
            || $maximumElapsedSeconds < self::LOGICAL_GET_BUDGET_SECONDS
            || $maximumElapsedSeconds > 3_600) {
            throw new \InvalidArgumentException('The ambiguous task search bounds are invalid.');
        }
    }

    public function read(AmbiguousSubmissionIdentity $identity, callable $beforePage): AmbiguousSubmissionEvidence
    {
        $client = $this->clients->forRequest($identity->requestId);
        $deadline = $this->clock->now()->modify('+'.$this->maximumElapsedSeconds.' seconds');
        $pagesRead = 0;
        $tasks = [];
        $complete = true;
        foreach ([
            [PveTaskQuery::active(limit: $this->pageSize), $this->activePageCap],
            [PveTaskQuery::archive(
                $identity->windowStart->getTimestamp(),
                $identity->windowEnd->getTimestamp(),
                limit: $this->pageSize,
            ), $this->archivePageCap],
        ] as [$query, $cap]) {
            for ($pageNumber = 0; $pageNumber < $cap; ++$pageNumber) {
                if ($pagesRead >= $this->totalPageCap
                    || $this->clock->now()->modify('+'.self::LOGICAL_GET_BUDGET_SECONDS.' seconds') > $deadline) {
                    return new AmbiguousSubmissionEvidence(false, array_values($tasks));
                }
                if (!$beforePage()) {
                    return new AmbiguousSubmissionEvidence(false, array_values($tasks));
                }
                $page = $client->taskPage($identity->node, $query);
                ++$pagesRead;
                $complete = $complete && $page->isComplete();
                foreach ($page->tasks as $task) {
                    $existing = $tasks[$task->upid->raw] ?? null;
                    if (!$existing instanceof PveBackupTask) {
                        $tasks[$task->upid->raw] = $task;
                        continue;
                    }
                    $enriched = $existing->enrich($task);
                    if (null === $enriched) {
                        $complete = false;
                    } else {
                        $tasks[$task->upid->raw] = $enriched;
                    }
                }
                if ($page->isShort()) {
                    break;
                }
                $query = $query->nextPage();
                if ($pageNumber + 1 === $cap) {
                    $complete = false;
                }
            }
        }

        ksort($tasks, SORT_STRING);

        return new AmbiguousSubmissionEvidence($complete, array_values($tasks));
    }
}
