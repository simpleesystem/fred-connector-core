<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Exceptions\VendorTransportException;

/**
 * Guzzle-backed transport for the vendor API.
 *
 * Wraps Guzzle's exception model into the SDK's typed exception
 * hierarchy: only {@see VendorTransportException} can come out of this
 * class. Non-2xx responses pass through with their status and decoded
 * JSON body so the typed {@see \Simplee\FredConnector\Client}
 * can map them to {@see \Simplee\FredConnector\Exceptions\VendorApiException}
 * with the right cloud `code`.
 *
 * `http_errors => false` is intentional: Guzzle's default is to throw
 * on 4xx/5xx, but the SDK's typed client maps those to its own
 * exceptions with structured context. Letting Guzzle throw would lose
 * the response body.
 */
class GuzzleHttpClient implements HttpClientInterface
{
    public function __construct(
        private readonly GuzzleClientInterface $guzzle,
        private readonly float $timeoutSeconds = Constants::DEFAULT_TIMEOUT_SECONDS,
        private readonly float $connectTimeoutSeconds = Constants::DEFAULT_CONNECT_TIMEOUT_SECONDS,
    ) {}

    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): array
    {
        $options = [
            'headers' => $headers + [
                Constants::HEADER_ACCEPT => Constants::CONTENT_TYPE_JSON,
                Constants::HEADER_CONTENT_TYPE => Constants::CONTENT_TYPE_JSON,
            ],
            'timeout' => $this->timeoutSeconds,
            'connect_timeout' => $this->connectTimeoutSeconds,
            'http_errors' => false,
        ];

        if ($jsonBody !== null) {
            $options['json'] = $jsonBody;
        }

        try {
            $response = $this->guzzle->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw new VendorTransportException(
                sprintf('Fred Cloud connect error: %s', $e->getMessage()),
                ['url' => $url, 'method' => $method],
                $e,
            );
        } catch (RequestException|GuzzleException $e) {
            throw new VendorTransportException(
                sprintf('Fred Cloud transport error: %s', $e->getMessage()),
                ['url' => $url, 'method' => $method],
                $e,
            );
        }

        $status = $response->getStatusCode();
        $bodyRaw = (string) $response->getBody();
        $bodyDecoded = $bodyRaw !== '' ? json_decode($bodyRaw, true) : [];
        if (! is_array($bodyDecoded)) {
            $bodyDecoded = ['raw' => $bodyRaw];
        }

        return ['status' => $status, 'body' => $bodyDecoded];
    }
}
