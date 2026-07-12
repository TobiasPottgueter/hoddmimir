<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pbs;

use InvalidArgumentException;

enum PbsTaskOutcome: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
    case Unknown = 'unknown';

    public static function fromRemoteStatus(string $status): self
    {
        $length = strlen($status);
        if (0 === $length || $length > 255 || $length !== strspn(
            $status,
            ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~',
        )) {
            throw new InvalidArgumentException('The PBS task status is invalid.');
        }
        if ('OK' === $status) {
            return self::Ok;
        }
        if ('unknown' === strtolower($status)) {
            return self::Unknown;
        }
        if (0 === strncmp($status, 'WARNINGS:', 9)) {
            return self::Warning;
        }
        return self::Error;
    }
}
