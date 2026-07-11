<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Application\Proxmox\Pve\PveReadFailure;
use App\Application\Proxmox\Pve\PveReadFailureCode;
use App\Infrastructure\Validation\ObjectPropertyInspector;
use JsonException;

final readonly class PveJsonEnvelopeDecoder
{
    public const MAXIMUM_BODY_BYTES = 8_388_608;

    public function decode(string $body): mixed
    {
        if (strlen($body) > self::MAXIMUM_BODY_BYTES) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidEnvelope);
        }

        try {
            $envelope = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidEnvelope);
        }

        if (!$envelope instanceof \stdClass) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidEnvelope);
        }

        $hasData = ObjectPropertyInspector::exists($envelope, 'data');
        if (!$hasData) {
            throw PveReadFailure::for(PveReadFailureCode::InvalidEnvelope);
        }

        return $envelope->data;
    }
}
