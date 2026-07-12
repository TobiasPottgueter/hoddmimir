<?php

declare(strict_types=1);

namespace App\Infrastructure\Proxmox\Pbs;

use Symfony\Component\HttpClient\NativeHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PbsNativeHttpClientFactory implements PbsHttpClientFactory
{
    public function __construct(private PbsCustomCaMaterializer $customCaMaterializer) {}

    public function create(PbsTlsConfiguration $tls): HttpClientInterface
    {
        return new NativeHttpClient($this->options($tls));
    }

    /** @return array<string, mixed> */
    public function options(PbsTlsConfiguration $tls): array
    {
        $options = [
            'verify_peer' => true,
            'verify_host' => true,
            'max_redirects' => 0,
            'timeout' => 30.0,
            'max_duration' => 30.0,
        ];
        if (PbsTlsMode::SystemCa === $tls->mode) {
            return $options;
        }
        if (PbsTlsMode::CustomCa === $tls->mode) {
            /** @var PbsCustomCaCertificate $ca */
            $ca = $tls->customCa;
            return $options + ['cafile' => $this->customCaMaterializer->materialize($ca)];
        }
        /** @var PbsCertificateFingerprint $pin */
        $pin = $tls->certificateFingerprint;
        return $options + ['peer_fingerprint' => ['sha256' => $pin->sha256]];
    }
}
