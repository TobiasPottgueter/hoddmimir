<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation;

final readonly class HttpsAuthorityHostNormalizer
{
    public static function normalize(string $host): ?string
    {
        if (str_starts_with($host, '[')) {
            if (!str_ends_with($host, ']')) {
                return null;
            }

            $host = substr($host, 1, -1);
            if (false === filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return null;
            }

            return '['.strtolower($host).']';
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return '['.strtolower($host).']';
        }

        if (str_ends_with($host, ']')) {
            return null;
        }

        if (false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $host;
        }

        $host = strtolower($host);
        if (strlen($host) > 253) {
            return null;
        }

        return 1 === preg_match(
            '/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/D',
            $host,
        ) ? $host : null;
    }
}
