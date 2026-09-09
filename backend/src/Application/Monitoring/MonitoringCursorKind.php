<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

enum MonitoringCursorKind: string
{
    case PveTasksArchive = 'pve_tasks_archive';
    case PbsTasksWindow = 'pbs_tasks_window';
}
