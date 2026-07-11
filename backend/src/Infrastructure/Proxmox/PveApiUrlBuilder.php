<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use App\Infrastructure\Validation\HttpsAuthorityHostNormalizer;
use InvalidArgumentException;

final readonly class PveApiUrlBuilder
{
    private string $authorityHost;

    public function __construct(string $host, private int $port = 8006)
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The PVE API port is invalid.');
        }

        $authorityHost = HttpsAuthorityHostNormalizer::normalize($host);
        if (null === $authorityHost) {
            throw new InvalidArgumentException('The PVE API host is invalid.');
        }

        $this->authorityHost = $authorityHost;
    }

    /**
     * @param list<string>                         $pathSegments
     * @param array<string, string|int|bool|null> $query
     */
    public function build(array $pathSegments, array $query = []): string
    {
        $url = sprintf('https://%s:%d/api2/json', $this->authorityHost, $this->port);
        foreach ($pathSegments as $segment) {
            if ($segment === '') {
                throw new InvalidArgumentException('PVE API path segments cannot be empty.');
            }

            $url .= '/'.rawurlencode($segment);
        }

        if ([] !== $query) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }
}
