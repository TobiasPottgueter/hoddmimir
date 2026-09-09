<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use App\Infrastructure\Validation\HttpsAuthorityHostNormalizer;
use InvalidArgumentException;

final readonly class PbsApiUrlBuilder
{
    private string $authorityHost;

    public function __construct(string $host, private int $port = 8007)
    {
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('The PBS API port is invalid.');
        }
        $host = HttpsAuthorityHostNormalizer::normalize($host);
        if (null === $host) {
            throw new InvalidArgumentException('The PBS API host is invalid.');
        }
        $this->authorityHost = $host;
    }

    public function build(PbsRequest $request): string
    {
        $url = sprintf('https://%s:%d/api2/json', $this->authorityHost, $this->port);
        foreach ($request->pathSegments as $segment) {
            $url .= '/'.rawurlencode($segment);
        }
        if ([] !== $request->query) {
            $url .= '?'.http_build_query($request->query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }
}
