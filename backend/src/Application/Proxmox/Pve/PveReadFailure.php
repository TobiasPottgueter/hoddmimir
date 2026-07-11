<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use RuntimeException;

final class PveReadFailure extends RuntimeException
{
    private const MESSAGES = [
        'credential_unavailable' => 'The PVE credential is unavailable.',
        'transport' => 'The PVE API transport failed.',
        'authentication' => 'The PVE API rejected the credential.',
        'permission_denied' => 'The PVE API denied the request.',
        'rate_limited' => 'The PVE API rate limit was reached.',
        'remote_unavailable' => 'The PVE API is temporarily unavailable.',
        'not_found' => 'The requested PVE API resource does not exist.',
        'http_status' => 'The PVE API returned an unexpected status.',
        'invalid_envelope' => 'The PVE API returned an invalid JSON envelope.',
        'invalid_response' => 'The PVE API response is incomplete.',
        'unsupported_version' => 'The PVE major version is unsupported.',
    ];

    private function __construct(public readonly PveReadFailureCode $failureCode)
    {
        parent::__construct(self::MESSAGES[$failureCode->value]);
    }

    public static function for(PveReadFailureCode $failureCode): self
    {
        return new self($failureCode);
    }
}
