<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

interface BackupTargetCandidateReadModel
{
    public function candidates(BackupTargetCandidateQuery $query): BackupTargetCandidatePage;
}
