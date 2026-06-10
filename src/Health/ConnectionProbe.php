<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Health;

use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Http\HttpClientInterface;
use Throwable;

/**
 * Live connectivity probe for the Fred Cloud vendor API.
 *
 * "Connected" must reflect a real authenticated round-trip, not merely
 * the presence of a configured base URL + service token. This issues
 * `GET /v1/vendor/qos/schema` with the configured credentials: a 2xx
 * means the site can actually reach Fred Cloud and the vendor token is
 * accepted. Anything else — an empty configuration, a non-2xx status, or
 * a transport failure — is treated as not connected. The probe never
 * throws; it answers a single boolean so the admin notice renders
 * deterministically.
 *
 * A transient 5xx or transport error is retried
 * ({@see Constants::CONNECTION_PROBE_MAX_ATTEMPTS}): the cloud runs on
 * Laravel Octane/FrankenPHP and the first request to hit a cold worker
 * can return a one-off 500 before the worker is warm, which would
 * otherwise pin the admin notice on "not connected" with a perfectly
 * valid token. Definitive failures (401/403/404) are NOT retried —
 * re-asking would not change the verdict.
 *
 * This path is intentionally SLS-free: it talks only to the Fred Cloud
 * vendor API, never the Simple License Server update channel.
 */
final class ConnectionProbe
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $serviceToken,
        private readonly int $maxAttempts = Constants::CONNECTION_PROBE_MAX_ATTEMPTS,
    ) {}

    public function isConnected(): bool
    {
        if (trim($this->baseUrl) === '' || trim($this->serviceToken) === '') {
            return false;
        }

        $url = rtrim($this->baseUrl, '/').Constants::API_ENDPOINT_QOS_SCHEMA;
        $attempts = max(1, $this->maxAttempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->http->request('GET', $url, [
                    Constants::HEADER_AUTHORIZATION => Constants::HEADER_BEARER_PREFIX.$this->serviceToken,
                ]);
            } catch (Throwable) {
                // Transport error (DNS/TLS/reset). Retry until attempts run
                // out, then report not connected.
                continue;
            }

            $status = is_int($response['status'] ?? null) ? $response['status'] : 0;

            if ($status >= Constants::HTTP_OK && $status < Constants::HTTP_MULTIPLE_CHOICES) {
                return true;
            }

            // Only a transient server-side failure (5xx) is worth retrying;
            // a definitive 3xx/4xx (auth, routing) will not change on retry.
            if ($status < Constants::HTTP_INTERNAL_SERVER_ERROR) {
                return false;
            }
        }

        return false;
    }
}
