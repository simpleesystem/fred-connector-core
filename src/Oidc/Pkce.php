<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use Simplee\FredConnector\Constants;

/**
 * PKCE (RFC 7636) verification. Only `S256` is supported — `plain` is
 * intentionally rejected so a public client cannot downgrade to an
 * interceptable challenge.
 */
final class Pkce
{
    public static function methodSupported(string $method): bool
    {
        return $method === Constants::OIDC_PKCE_METHOD_S256;
    }

    /**
     * Constant-time check that `code_verifier` matches the stored
     * `code_challenge` under S256.
     */
    public static function verify(string $codeVerifier, string $codeChallenge, string $method): bool
    {
        if (! self::methodSupported($method)) {
            return false;
        }
        if ($codeVerifier === '' || $codeChallenge === '') {
            return false;
        }

        $computed = Base64Url::encode(hash('sha256', $codeVerifier, true));

        return hash_equals($codeChallenge, $computed);
    }

    private function __construct() {}
}
