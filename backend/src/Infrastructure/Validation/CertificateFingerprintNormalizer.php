<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class CertificateFingerprintNormalizer
{
    public static function normalizeSha256(string $fingerprint): ?string
    {
        if (1 === preg_match('/\A[0-9A-Fa-f]{64}\z/D', $fingerprint)) {
            return strtolower($fingerprint);
        }

        if (1 === preg_match('/\A(?:[0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}\z/D', $fingerprint)) {
            return strtolower(str_replace(':', '', $fingerprint));
        }

        return null;
    }
}
