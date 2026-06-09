<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Http {
    /**
     * Namespaced overrides of the WordPress HTTP API functions. PHP
     * resolves these for the unqualified calls inside {@see WpHttpClient}
     * (same namespace) before falling back to the global scope, letting
     * the test drive the transport without a live WordPress runtime.
     *
     * @param  array<string, mixed>  $args
     */
    function wp_remote_request(string $url, array $args = []): mixed
    {
        $GLOBALS['__woo_wp_remote_request_calls'][] = ['url' => $url, 'args' => $args];
        $handler = $GLOBALS['__woo_wp_remote_request_handler'] ?? null;

        return is_callable($handler) ? $handler($url, $args) : ['response' => ['code' => 200], 'body' => ''];
    }

    function wp_remote_retrieve_response_code(mixed $response): int
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }

    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

namespace Simplee\FredConnector\Tests\Unit\Http {

    use PHPUnit\Framework\TestCase;
    use Simplee\FredConnector\Constants;
    use Simplee\FredConnector\Exceptions\VendorTransportException;
    use Simplee\FredConnector\Http\WpHttpClient;
    use Simplee\FredConnector\Tests\TestingConstants;
    use WP_Error;

    final class WpHttpClientTest extends TestCase
    {
        private function qosUrl(): string
        {
            return TestingConstants::BASE_URL.Constants::API_ENDPOINT_QOS_SCHEMA;
        }

        protected function setUp(): void
        {
            $GLOBALS['__woo_wp_remote_request_calls'] = [];
            $GLOBALS['__woo_wp_remote_request_handler'] = null;
        }

        public function test_request_returns_status_and_decoded_body_on_success(): void
        {
            $GLOBALS['__woo_wp_remote_request_handler'] = static fn (): array => [
                'response' => ['code' => Constants::HTTP_OK],
                'body' => '{"keys":[]}',
            ];

            $result = (new WpHttpClient)->request('GET', $this->qosUrl(), [
                Constants::HEADER_AUTHORIZATION => Constants::HEADER_BEARER_PREFIX.TestingConstants::VENDOR_SERVICE_TOKEN,
            ]);

            $this->assertSame(Constants::HTTP_OK, $result['status']);
        }

        public function test_request_forwards_the_authorization_header(): void
        {
            $GLOBALS['__woo_wp_remote_request_handler'] = static fn (): array => [
                'response' => ['code' => Constants::HTTP_OK],
                'body' => '{}',
            ];

            (new WpHttpClient)->request('GET', $this->qosUrl(), [
                Constants::HEADER_AUTHORIZATION => Constants::HEADER_BEARER_PREFIX.TestingConstants::VENDOR_SERVICE_TOKEN,
            ]);

            $headers = $GLOBALS['__woo_wp_remote_request_calls'][0]['args']['headers'];
            $this->assertSame(
                Constants::HEADER_BEARER_PREFIX.TestingConstants::VENDOR_SERVICE_TOKEN,
                $headers[Constants::HEADER_AUTHORIZATION],
            );
        }

        public function test_request_throws_vendor_transport_exception_on_wp_error(): void
        {
            $GLOBALS['__woo_wp_remote_request_handler'] = static fn (): WP_Error => new WP_Error(
                'http_request_failed',
                'cURL error 60: SSL certificate problem',
            );

            $this->expectException(VendorTransportException::class);

            (new WpHttpClient)->request('GET', $this->qosUrl());
        }

        public function test_request_wraps_non_array_body_as_raw(): void
        {
            $GLOBALS['__woo_wp_remote_request_handler'] = static fn (): array => [
                'response' => ['code' => Constants::HTTP_OK],
                'body' => 'not-json',
            ];

            $result = (new WpHttpClient)->request('GET', $this->qosUrl());

            $this->assertSame('not-json', $result['body']['raw']);
        }
    }
}
