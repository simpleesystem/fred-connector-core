<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Http;

use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Exceptions\VendorTransportException;
use WP_Error;

/**
 * WordPress HTTP API transport for the Fred Cloud vendor API.
 *
 * Uses WordPress's own HTTP layer ({@see \wp_remote_request()}) instead of
 * Guzzle for the connection probe. Guzzle's cURL handler resolves the CA
 * bundle from the host (system path / php.ini), and on managed WordPress
 * hosts that ship no CA bundle at a path Guzzle probes it aborts every
 * outbound TLS request with "No system CA bundle could be found" — which
 * the probe catches and reports as "not connected" even when the vendor
 * token is valid and the cloud is reachable. The WordPress HTTP API ships
 * its own CA bundle ({@see ABSPATH}wp-includes/certificates/ca-bundle.crt)
 * and selects a transport that works on the host, so the probe succeeds
 * wherever WordPress's own update/REST calls do.
 *
 * This path is intentionally SLS-free: it talks only to the Fred Cloud
 * vendor API, never the Simple License Server update channel.
 */
class WpHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly float $timeoutSeconds = Constants::DEFAULT_TIMEOUT_SECONDS,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): array
    {
        $args = [
            'method' => strtoupper($method),
            'timeout' => $this->timeoutSeconds,
            'headers' => $headers + [
                Constants::HEADER_ACCEPT => Constants::CONTENT_TYPE_JSON,
                Constants::HEADER_CONTENT_TYPE => Constants::CONTENT_TYPE_JSON,
            ],
        ];

        if ($jsonBody !== null) {
            $args['body'] = (string) json_encode($jsonBody);
        }

        $response = wp_remote_request($url, $args);

        if ($response instanceof WP_Error) {
            throw new VendorTransportException(
                sprintf('Fred Cloud transport error: %s', $response->get_error_message()),
                ['url' => $url, 'method' => $method],
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $bodyRaw = (string) wp_remote_retrieve_body($response);
        $bodyDecoded = $bodyRaw !== '' ? json_decode($bodyRaw, true) : [];
        if (! is_array($bodyDecoded)) {
            $bodyDecoded = ['raw' => $bodyRaw];
        }

        return ['status' => $status, 'body' => $bodyDecoded];
    }
}
