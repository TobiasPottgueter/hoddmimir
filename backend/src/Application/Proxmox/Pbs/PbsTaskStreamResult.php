<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

/** Deterministic bounded-scan evidence for one PBS task filter/pass stream. */
final readonly class PbsTaskStreamResult
{
    public function __construct(
        public PbsTaskFilterFamily $family,
        public PbsTaskPass $pass,
        public PbsTaskStreamStatus $status,
        public int $pagesRead,
        public int $rowsRead,
        public int $itemsSeen,
        public bool $truncated,
        public bool $historyGap,
        public ?PbsTaskScanIssueCode $issueCode,
    ) {
        if (min($pagesRead, $rowsRead, $itemsSeen) < 0
            || $itemsSeen > $rowsRead
            || (PbsTaskStreamStatus::Complete === $status)
                !== (null === $issueCode && !$truncated && !$historyGap)
            || (PbsTaskStreamStatus::Complete !== $status && null === $issueCode)
            || (PbsTaskStreamStatus::Failed === $status && (0 !== $pagesRead || 0 !== $rowsRead || 0 !== $itemsSeen))
            || $historyGap !== (PbsTaskPass::History === $pass && $truncated)) {
            throw new InvalidArgumentException('The PBS task stream result is inconsistent.');
        }
    }

    public function isComplete(): bool
    {
        return PbsTaskStreamStatus::Complete === $this->status;
    }

    public function withIssue(PbsTaskScanIssueCode $issueCode): self
    {
        if (!$this->isComplete()) {
            return $this;
        }
        return new self(
            $this->family,
            $this->pass,
            PbsTaskStreamStatus::Partial,
            $this->pagesRead,
            $this->rowsRead,
            $this->itemsSeen,
            false,
            false,
            $issueCode,
        );
    }
}
