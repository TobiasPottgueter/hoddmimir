<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

enum PbsTaskFilterFamily: string
{
    case Backup = 'backup';
    case Prune = 'prune';
    case Sync = 'syncjob';
    case Verify = 'verif';

    public function allows(string $workerType): bool
    {
        $allowed = [
            'backup' => ['backup'],
            'prune' => ['prune', 'prunejob'],
            'syncjob' => ['syncjob'],
            'verif' => ['verificationjob', 'verify', 'verify_group', 'verify_snapshot'],
        ];
        foreach ($allowed[$this->value] as $allowedType) {
            if ($workerType === $allowedType) {
                return true;
            }
        }
        return false;
    }

    public static function allowsAny(string $workerType): bool
    {
        return null !== self::forWorkerType($workerType);
    }

    public static function forWorkerType(string $workerType): ?self
    {
        $families = [
            'backup' => self::Backup,
            'prune' => self::Prune,
            'prunejob' => self::Prune,
            'syncjob' => self::Sync,
            'verificationjob' => self::Verify,
            'verify' => self::Verify,
            'verify_group' => self::Verify,
            'verify_snapshot' => self::Verify,
        ];
        return $families[$workerType] ?? null;
    }
}
