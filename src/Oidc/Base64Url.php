<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

/**
 * RFC 7515 base64url (no padding) encode/decode helpers shared across
 * the OIDC provider (JWK parameters, PKCE, code generation).
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @return string  raw decoded bytes (empty string on invalid input is
     *                 NOT used — callers needing strictness should compare
     *                 re-encoded output)
     */
    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private function __construct() {}
}
