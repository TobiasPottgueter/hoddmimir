<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

enum MonitoringScopeType: string
{
    case PveBackupJobs = 'pve_backup_jobs';
    case PveTasksActive = 'pve_tasks_active';
    case PveTasksArchive = 'pve_tasks_archive';
    case PbsPruneJobs = 'pbs_prune_jobs';
    case PbsSyncJobs = 'pbs_sync_jobs';
    case PbsVerifyJobs = 'pbs_verify_jobs';
    case PbsTasksRunning = 'pbs_tasks_running';
    case PbsTasksWindow = 'pbs_tasks_window';
}
