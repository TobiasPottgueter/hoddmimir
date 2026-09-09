<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox;

use Symfony\Component\HttpClient\CurlHttpClient;
use App\Infrastructure\Proxmox\ProxmoxConnectionHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PveNativeHttpClientFactory implements PveHttpClientFactory
{
    public function __construct(private PveCustomCaMaterializer $customCaMaterializer)
    {
    }

    public function create(PveTlsConfiguration $tls): HttpClientInterface
    {
        $options = $this->options($tls);
        $fingerprint = $tls->certificateFingerprint?->sha256;
        unset($options['peer_fingerprint']);
        return new ProxmoxConnectionHttpClient(new CurlHttpClient($options), $fingerprint);
    }

    /** @return array<string, mixed> */
    public function options(PveTlsConfiguration $tls): array
    {
        $options = [
            'verify_peer' => true,
            'verify_host' => true,
            'max_redirects' => 0,
            'timeout' => 30.0,
            'max_duration' => 30.0,
        ];

        /** @var PveCustomCaCertificate $customCa */
        $customCa = $tls->customCa;
        /** @var PveCertificateFingerprint $certificateFingerprint */
        $certificateFingerprint = $tls->certificateFingerprint;

        if (PveTlsMode::SystemCa === $tls->mode) {
            return $options;
        }

        if (PveTlsMode::CustomCa === $tls->mode) {
            $caFile = $this->customCaMaterializer->materialize($customCa);
            return $options + ['cafile' => $caFile];
        }

        // Fingerprint pinning is an exclusive trust mode. The exact leaf
        // certificate digest replaces CA-chain and hostname trust, while the
        // pre-request certificate check still fails closed before HTTP headers are sent when
        // the presented certificate does not match the configured pin.
        return array_replace($options, [
            'verify_peer' => false,
            'verify_host' => false,
            'peer_fingerprint' => ['sha256' => $certificateFingerprint->sha256],
        ]);
    }
}
