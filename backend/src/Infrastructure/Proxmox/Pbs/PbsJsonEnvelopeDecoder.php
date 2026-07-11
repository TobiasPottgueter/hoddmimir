<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Application\Proxmox\Pbs\PbsReadFailure;
use App\Application\Proxmox\Pbs\PbsReadFailureCode;
use App\Infrastructure\Validation\ObjectPropertyInspector;
use JsonException;

final readonly class PbsJsonEnvelopeDecoder
{
    public function decode(string $body, int $maximumBytes): PbsApiEnvelope
    {
        if (strlen($body) > $maximumBytes) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidEnvelope);
        }
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidEnvelope);
        }
        if (!$decoded instanceof \stdClass || !ObjectPropertyInspector::exists($decoded, 'data')) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidEnvelope);
        }
        $digest = ObjectPropertyInspector::exists($decoded, 'digest') ? $decoded->digest : null;
        if (null !== $digest && !is_string($digest)) {
            throw PbsReadFailure::for(PbsReadFailureCode::InvalidEnvelope);
        }
        return new PbsApiEnvelope($decoded->data, $digest);
    }
}
