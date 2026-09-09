<?php

declare(strict_types=1);

namespace App\Application\Backup\Notification;

final readonly class MatrixNotificationFormatter
{
    private const array GUEST_TYPE_LABELS = [
        'qemu' => 'VM',
        'lxc' => 'CT',
    ];

    public function format(BackupNotification $notification): string
    {
        $guestType = self::GUEST_TYPE_LABELS[$notification->guestType->value];
        $common = [
            \sprintf('%s: %s (%s %d)', $guestType, $notification->guestName, $guestType, $notification->vmid),
            'Knoten: '.$notification->node,
            'Backupziel: '.$notification->targetLabel,
        ];

        if (BackupNotificationKind::Failure === $notification->kind) {
            $retry = $notification->nextRetryAt ?? throw new \LogicException('A failure notification lost its retry time.');
            $headline = null === $notification->attempt
                ? \sprintf('Backupstart blockiert – Prüfversuch Nr. %d', $notification->checkNumber)
                : \sprintf('Backup fehlgeschlagen – Versuch Nr. %d', $notification->attempt);

            return \implode("\n", [
                $headline,
                ...$common,
                'Fehler: '.$notification->problemCode->value
                    .(null === $notification->detailCode ? '' : ' / '.$notification->detailCode),
                'Fehlerzeit: '.$notification->occurredAt->format('Y-m-d H:i:s.u').' UTC',
                'Nächster Versuch: '.$retry->format('Y-m-d H:i:s.u').' UTC',
            ]);
        }

        if (BackupNotificationKind::AttentionRequired === $notification->kind) {
            $attempt = $notification->attempt ?? $notification->checkNumber;
            return \implode("\n", [
                \sprintf('Backupzustand unklar – Versuch Nr. %d erfordert Prüfung', $attempt),
                ...$common,
                'Problem: '.$notification->problemCode->value
                    .(null === $notification->detailCode ? '' : ' / '.$notification->detailCode),
                'Zeitpunkt: '.$notification->occurredAt->format('Y-m-d H:i:s.u').' UTC',
                'Es wird kein automatischer vzdump-Retry ausgeführt.',
            ]);
        }

        return \implode("\n", [
            \sprintf('Backup wieder erfolgreich – nach %d Fehlversuch(en)', $notification->consecutiveFailures),
            ...$common,
            \sprintf('Fehlerdauer: %d Sekunden', $notification->occurredAt->getTimestamp() - $notification->openedAt->getTimestamp()),
            'Erfolgszeit: '.$notification->occurredAt->format('Y-m-d H:i:s.u').' UTC',
        ]);
    }
}
