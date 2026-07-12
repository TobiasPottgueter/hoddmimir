<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

final readonly class PbsTaskScanSnapshot
{
    /** @var list<PbsTaskObservation> */ public array $tasks;
    /** @var list<PbsTaskScanIssue> */ public array $issues;
    /** @var list<PbsTaskStreamResult> */ public array $streams;

    /**
     * @param list<PbsTaskObservation> $tasks
     * @param list<PbsTaskScanIssue>   $issues
     * @param list<PbsTaskStreamResult> $streams
     */
    public function __construct(public PbsTaskWindow $window, array $tasks, array $issues, array $streams = [])
    {
        $byUpid = [];
        $conflicts = [];
        foreach ($tasks as $task) {
            if (!isset($byUpid[$task->upid->value])) {
                $byUpid[$task->upid->value] = $task;
                continue;
            }
            try {
                $byUpid[$task->upid->value] = $byUpid[$task->upid->value]->merge($task);
            } catch (\InvalidArgumentException) {
                $family = $this->familyFor($task->upid->workerType);
                $conflictKey = $family->value."\0".PbsTaskPass::History->value;
                $conflicts[$conflictKey] = new PbsTaskScanIssue(
                    $family,
                    PbsTaskPass::History,
                    PbsTaskScanIssueCode::ConflictingTaskEvidence,
                );
                $byUpid[$task->upid->value] = $this->deterministicTask($byUpid[$task->upid->value], $task);
            }
        }
        ksort($byUpid, SORT_STRING);
        $this->tasks = array_values($byUpid);
        $issueMap = [];
        foreach ([...$issues, ...array_values($conflicts)] as $issue) {
            $issueMap[$issue->family->value."\0".$issue->pass->value."\0".$issue->code->value] = $issue;
        }
        ksort($issueMap, SORT_STRING);
        $this->issues = array_values($issueMap);
        $streamMap = [];
        foreach ($streams as $stream) {
            $key = $stream->family->value."\0".$stream->pass->value;
            if (isset($streamMap[$key])) {
                throw new \InvalidArgumentException('The PBS task scan contains duplicate streams.');
            }
            $streamMap[$key] = isset($conflicts[$key])
                ? $stream->withIssue(PbsTaskScanIssueCode::ConflictingTaskEvidence)
                : $stream;
        }
        ksort($streamMap, SORT_STRING);
        $this->streams = array_values($streamMap);
    }

    public function isComplete(): bool
    {
        return [] === $this->issues;
    }

    public function permitsAbsenceDecisions(): bool
    {
        return false;
    }

    private function familyFor(string $workerType): PbsTaskFilterFamily
    {
        /** @var PbsTaskFilterFamily $family Task-observation allowlist invariant. */
        $family = PbsTaskFilterFamily::forWorkerType($workerType);
        return $family;
    }

    private function deterministicTask(PbsTaskObservation $left, PbsTaskObservation $right): PbsTaskObservation
    {
        $signature = static function (PbsTaskObservation $task): string {
            /** @var PbsTaskOutcome $outcome Terminal-conflict invariant. */
            $outcome = $task->outcome;
            /** @var int $endTime Terminal-conflict invariant. */
            $endTime = $task->endTime;
            return implode("\0", [$outcome->value, (string) $endTime, $task->reportedNode ?? '']);
        };
        return $signature($left) <= $signature($right) ? $left : $right;
    }
}
