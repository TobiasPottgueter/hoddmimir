<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use InvalidArgumentException;

final readonly class BuildPveVzdumpPayload
{
    private const array LEGACY_SENDMAIL_NOTIFICATION_MAJORS = [8 => true, 9 => true];

    /** @return array<string, string|int> */
    public function build(PveVersion $version, PveBackupSubmission $submission): array
    {
        $capabilities = PveBackupJobCapabilities::forMajor($version->major);
        if (!$capabilities->supportsLegacyMaxFiles && null !== $submission->legacyMaxFiles) {
            throw new InvalidArgumentException('PVE 9 does not support the legacy maxfiles parameter.');
        }

        $payload = [
            'vmid' => $submission->vmid,
            'storage' => $submission->storage,
            'mode' => $submission->mode->value,
            'compress' => $submission->compression->value,
        ];
        if (null !== $submission->pruneBackups) {
            $payload['prune-backups'] = $this->prunePropertyString($submission->pruneBackups);
        }
        if (null !== $submission->legacyMaxFiles) {
            $payload['maxfiles'] = $submission->legacyMaxFiles;
        }
        if (isset(self::LEGACY_SENDMAIL_NOTIFICATION_MAJORS[$version->major])) {
            $payload['notification-mode'] = 'legacy-sendmail';
        }
        $payload['mailto'] = $submission->failureNotificationRecipients->parameterValue();
        $payload['mailnotification'] = 'failure';

        return $payload;
    }

    private function prunePropertyString(PvePruneBackups $pruneBackups): string
    {
        $propertyString = '';
        foreach ($pruneBackups->signature() as $name => $value) {
            if ('' !== $propertyString) {
                $propertyString .= ',';
            }
            $propertyString .= $name.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }

        return $propertyString;
    }
}
