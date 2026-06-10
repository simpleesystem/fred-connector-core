<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Exceptions\VendorTransportException;
use Simplee\FredConnector\Health\ConnectionProbe;
use Simplee\FredConnector\Http\InMemoryHttpClient;
use Simplee\FredConnector\Tests\TestingConstants;

/**
 * Reconciled from the WooCommerce vendor connector's and the login
 * connector's (previously divergent) ConnectionProbe suites. The probe
 * is standardized on the HttpClientInterface-based design; the canonical
 * retry contract is: 2xx => connected; 5xx/transport => retry up to max
 * attempts (Octane cold-start tolerance); other 4xx => definitive false;
 * empty config => false.
 */
final class ConnectionProbeTest extends TestCase
{
    private function qosUrl(): string
    {
        return TestingConstants::BASE_URL.Constants::API_ENDPOINT_QOS_SCHEMA;
    }

    private function makeProbe(InMemoryHttpClient $http, string $token = TestingConstants::VENDOR_SERVICE_TOKEN): ConnectionProbe
    {
        return new ConnectionProbe($http, TestingConstants::BASE_URL, $token);
    }

    public function test_is_connected_is_true_when_qos_schema_returns_two_hundred(): void
    {
        $http = new InMemoryHttpClient;
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_OK, ['keys' => []]);

        $this->assertTrue($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_sends_the_bearer_token(): void
    {
        $http = new InMemoryHttpClient;
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_OK, ['keys' => []]);

        $this->makeProbe($http)->isConnected();

        $this->assertSame(
            Constants::HEADER_BEARER_PREFIX.TestingConstants::VENDOR_SERVICE_TOKEN,
            $http->requests[0]['headers'][Constants::HEADER_AUTHORIZATION] ?? '',
        );
    }

    public function test_is_connected_is_false_when_token_is_rejected(): void
    {
        $http = new InMemoryHttpClient;
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_UNAUTHORIZED, ['error' => 'Invalid vendor service token.']);

        $this->assertFalse($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_is_false_when_cloud_keeps_returning_server_error(): void
    {
        // Sustained 5xx (cloud genuinely down) reports not-connected even
        // after the retries. Enqueue one 500 per attempt.
        $http = new InMemoryHttpClient;
        for ($i = 0; $i < Constants::CONNECTION_PROBE_MAX_ATTEMPTS; $i++) {
            $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_INTERNAL_SERVER_ERROR, ['message' => 'Server Error']);
        }

        $this->assertFalse($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_retries_a_transient_server_error_then_succeeds(): void
    {
        // Octane cold-start blip: first request 500s, the retry hits a warm
        // worker and returns 200. The probe must report connected.
        $http = new InMemoryHttpClient;
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_INTERNAL_SERVER_ERROR, ['message' => 'Server Error']);
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_OK, ['keys' => []]);

        $this->assertTrue($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_retries_a_transport_failure_then_succeeds(): void
    {
        $http = new InMemoryHttpClient;
        $http->enqueueThrow(new VendorTransportException('connection reset'));
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_OK, ['keys' => []]);

        $this->assertTrue($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_does_not_retry_a_rejected_token(): void
    {
        // A 401 is definitive — the probe must not waste a second round-trip
        // on it. Only one response is enqueued; a retry would throw on the
        // empty queue and fail this test (the empty queue throws a transport
        // exception, which a retrying probe would treat as one more attempt
        // and record as an extra request).
        $http = new InMemoryHttpClient;
        $http->enqueueResponse('GET', $this->qosUrl(), Constants::HTTP_UNAUTHORIZED, ['error' => 'Invalid vendor service token.']);

        $this->assertFalse($this->makeProbe($http)->isConnected());
        $this->assertCount(1, $http->requests);
    }

    public function test_is_connected_is_false_on_sustained_transport_failure(): void
    {
        $http = new InMemoryHttpClient;
        for ($i = 0; $i < Constants::CONNECTION_PROBE_MAX_ATTEMPTS; $i++) {
            $http->enqueueThrow(new VendorTransportException('connect error'));
        }

        $this->assertFalse($this->makeProbe($http)->isConnected());
    }

    public function test_is_connected_is_false_when_token_is_missing(): void
    {
        $http = new InMemoryHttpClient;

        $this->assertFalse($this->makeProbe($http, token: '')->isConnected());
        $this->assertSame([], $http->requests);
    }

    public function test_is_connected_is_false_when_base_url_is_missing(): void
    {
        $http = new InMemoryHttpClient;

        $probe = new ConnectionProbe($http, '', TestingConstants::VENDOR_SERVICE_TOKEN);

        $this->assertFalse($probe->isConnected());
        $this->assertSame([], $http->requests);
    }
}
