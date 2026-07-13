<?php

declare(strict_types=1);

namespace App\Application\Target\ReadModel;

interface ConfiguredBackupTargetReadModel
{
    public function targets(ConfiguredBackupTargetQuery $query): ConfiguredBackupTargetPage;
}
