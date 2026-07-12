<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\PveBackup;

use App\Application\Proxmox\Pve\PveBackupApiFailure;
use App\Application\Proxmox\Pve\PveBackupApiFailureCode;
use App\Infrastructure\Validation\ObjectPropertyInspector;
use JsonException;

final readonly class PveBackupJsonEnvelopeDecoder
{
    public const int MAXIMUM_BODY_BYTES = 8_388_608;

    public function decode(string $body): mixed
    {
        if (strlen($body) > self::MAXIMUM_BODY_BYTES) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidEnvelope);
        }
        try {
            $envelope = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidEnvelope);
        }
        if (!$envelope instanceof \stdClass || !ObjectPropertyInspector::exists($envelope, 'data')) {
            throw PveBackupApiFailure::for(PveBackupApiFailureCode::InvalidEnvelope);
        }

        return $envelope->data;
    }
}
