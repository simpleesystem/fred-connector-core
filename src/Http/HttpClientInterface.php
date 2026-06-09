<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Http;

/**
 * Thin transport seam.
 *
 * Every vendor API call goes through this contract so unit tests can
 * inject a deterministic in-memory transport (see {@see InMemoryHttpClient})
 * without standing up a real HTTP fixture. The Guzzle-backed
 * implementation is the only production binding today.
 */
interface HttpClientInterface
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $jsonBody
     * @return array{status: int, body: array<string, mixed>}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $jsonBody = null,
    ): array;
}
