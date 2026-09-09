<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class PemCertificateBundleNormalizer
{
    private const MAXIMUM_BUNDLE_BYTES = 262_144;
    private const MAXIMUM_CERTIFICATES = 16;

    public static function normalize(string $pem): ?string
    {
        if (strlen($pem) > self::MAXIMUM_BUNDLE_BYTES) {
            return null;
        }

        $trimmedPem = trim($pem);
        $matchCount = preg_match_all(
            '/-----BEGIN CERTIFICATE-----\R[A-Za-z0-9+\/=\r\n]+-----END CERTIFICATE-----/D',
            $trimmedPem,
            $matches,
        );
        if ($matchCount < 1 || $matchCount > self::MAXIMUM_CERTIFICATES) {
            return null;
        }

        $remainder = str_replace($matches[0], '', $trimmedPem);
        if (trim($remainder) !== '') {
            return null;
        }

        $normalized = '';
        foreach ($matches[0] as $certificate) {
            if (false === @openssl_x509_read($certificate)) {
                return null;
            }

            $normalized .= trim($certificate)."\n";
        }

        return $normalized;
    }
}
