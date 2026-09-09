<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Closure;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final readonly class CanonicalTrustedProxyEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, Closure $getEnv): string
    {
        $value = $getEnv($name);

        if (!is_string($value)) {
            throw new RuntimeException(sprintf('Environment variable "%s" must resolve to a string.', $name));
        }

        if ('' === $value) {
            return '';
        }

        $packed = inet_pton($value);

        if (false === $packed
            || $value !== inet_ntop($packed)
            || '0.0.0.0' === $value
            || '::' === $value) {
            throw new RuntimeException(sprintf(
                'Environment variable "%s" must be empty or contain exactly one canonical IP address.',
                $name,
            ));
        }

        return $value;
    }

    /** @return array<string, string> */
    public static function getProvidedTypes(): array
    {
        return ['hoddmimir_trusted_proxy' => 'string'];
    }
}
