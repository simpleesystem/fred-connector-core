<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use Simplee\FredConnector\Constants;

/**
 * Issues and consumes single-use authorization codes.
 *
 * Codes are opaque 256-bit random strings; only their SHA-256 hash is
 * used as the transient key, so the stored record can never be used to
 * reconstruct the code. `consume()` deletes the record before returning
 * it (single-use) and rejects expired records — defeating replay.
 *
 * Storage uses WP transients via injectable callables so the store is
 * unit-testable without WordPress.
 */
class AuthorizationCodeStore
{
    /**
     * @var callable(): int
     */
    private $clock;

    /**
     * @var callable(): string
     */
    private $codeFactory;

    /**
     * @var callable(string, mixed, int): bool
     */
    private $writeTransient;

    /**
     * @var callable(string): mixed
     */
    private $readTransient;

    /**
     * @var callable(string): bool
     */
    private $deleteTransient;

    /**
     * @param  (callable(): int)|null  $clock
     * @param  (callable(): string)|null  $codeFactory
     * @param  (callable(string, mixed, int): bool)|null  $writeTransient
     * @param  (callable(string): mixed)|null  $readTransient
     * @param  (callable(string): bool)|null  $deleteTransient
     */
    public function __construct(
        ?callable $clock = null,
        ?callable $codeFactory = null,
        ?callable $writeTransient = null,
        ?callable $readTransient = null,
        ?callable $deleteTransient = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->codeFactory = $codeFactory ?? static fn (): string => Base64Url::encode(random_bytes(32));
        $this->writeTransient = $writeTransient ?? static function (string $key, mixed $value, int $ttl): bool {
            return function_exists('set_transient') ? (bool) set_transient($key, $value, $ttl) : false;
        };
        $this->readTransient = $readTransient ?? static function (string $key): mixed {
            return function_exists('get_transient') ? get_transient($key) : false;
        };
        $this->deleteTransient = $deleteTransient ?? static function (string $key): bool {
            return function_exists('delete_transient') ? (bool) delete_transient($key) : false;
        };
    }

    /**
     * Persist `$code` state and return the opaque code to hand the client.
     */
    public function issue(
        string $clientId,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        string $scope,
        ?string $nonce,
        ResolvedIdentity $identity,
        int $ttlSeconds = Constants::OIDC_AUTH_CODE_TTL_SECONDS,
    ): string {
        $now = ($this->clock)();
        $ttl = max(1, $ttlSeconds);
        $code = ($this->codeFactory)();

        $record = new AuthorizationCode(
            clientId: $clientId,
            redirectUri: $redirectUri,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
            scope: $scope,
            nonce: $nonce,
            identity: $identity,
            expiresAt: $now + $ttl,
        );

        ($this->writeTransient)($this->key($code), $record->toArray(), $ttl);

        return $code;
    }

    /**
     * Single-use consume: returns the record and removes it; null when
     * missing or expired.
     */
    public function consume(string $code): ?AuthorizationCode
    {
        if ($code === '') {
            return null;
        }
        $key = $this->key($code);
        $stored = ($this->readTransient)($key);
        ($this->deleteTransient)($key);

        if (! is_array($stored)) {
            return null;
        }

        $record = AuthorizationCode::fromArray($stored);
        if ($record->expiresAt < ($this->clock)()) {
            return null;
        }

        return $record;
    }

    private function key(string $code): string
    {
        return Constants::OIDC_CODE_TRANSIENT_PREFIX.hash('sha256', $code);
    }
}
