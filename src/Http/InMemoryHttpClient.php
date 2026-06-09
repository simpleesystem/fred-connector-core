<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Http;

use Simplee\FredConnector\Exceptions\VendorTransportException;

/**
 * Test double for {@see HttpClientInterface}.
 *
 * Records every outbound request so unit tests can pin the exact wire
 * shape of vendor API calls (method, URL, headers, JSON body) without a
 * mocking framework. Responses are pre-loaded as a queue per (method,
 * url) pair; an unmatched request throws so a misconfigured test fails
 * loudly.
 */
class InMemoryHttpClient implements HttpClientInterface
{
    /**
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @var array<string, list<array{status: int, body: array<string, mixed>}>>
     */
    private array $responses = [];

    /**
     * @var list<\Throwable>
     */
    private array $thrown = [];

    /**
     * @param  array<string, mixed>  $body
     */
    public function enqueueResponse(string $method, string $url, int $status, array $body): void
    {
        $key = strtoupper($method).' '.$url;
        $this->responses[$key][] = ['status' => $status, 'body' => $body];
    }

    public function enqueueThrow(\Throwable $error): void
    {
        $this->thrown[] = $error;
    }

    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): array
    {
        $this->requests[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $jsonBody,
        ];

        if ($this->thrown !== []) {
            throw array_shift($this->thrown);
        }

        $key = strtoupper($method).' '.$url;
        if (! isset($this->responses[$key]) || $this->responses[$key] === []) {
            throw new VendorTransportException(sprintf('No response queued for %s', $key));
        }

        return array_shift($this->responses[$key]);
    }
}
