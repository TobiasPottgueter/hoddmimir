<?php

declare(strict_types=1);

namespace App\Application\Proxmox\Pve;

use RuntimeException;

final class PveBackupApiFailure extends RuntimeException
{
    private const array MESSAGES = [
        'credential_unavailable' => 'The PVE backup credential is unavailable.',
        'configuration' => 'The PVE backup client configuration is invalid.',
        'transport' => 'The PVE backup API transport failed.',
        'authentication' => 'The PVE backup API rejected the credential.',
        'permission_denied' => 'The PVE backup API denied the request.',
        'rate_limited' => 'The PVE backup API rate limit was reached.',
        'remote_unavailable' => 'The PVE backup API is temporarily unavailable.',
        'not_found' => 'The requested PVE backup API resource does not exist.',
        'http_status' => 'The PVE backup API returned an unexpected status.',
        'invalid_envelope' => 'The PVE backup API returned an invalid JSON envelope.',
        'invalid_response' => 'The PVE backup API response is incomplete.',
        'unsupported_version' => 'The PVE backup API version is unsupported.',
    ];

    private function __construct(public readonly PveBackupApiFailureCode $failureCode)
    {
        parent::__construct(self::MESSAGES[$failureCode->value]);
    }

    public static function for(PveBackupApiFailureCode $failureCode): self
    {
        return new self($failureCode);
    }
}
