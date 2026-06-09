<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use Simplee\FredConnector\Constants;

/**
 * Builds the OpenID Provider metadata document (RFC 8414 /
 * OpenID Connect Discovery 1.0) served at
 * `{issuer}/.well-known/openid-configuration`.
 *
 * Advertises exactly what this provider implements: authorization-code
 * flow with mandatory PKCE (S256) and EdDSA-signed tokens.
 */
final class Discovery
{
    /**
     * @return array<string, mixed>
     */
    public static function document(string $issuer): array
    {
        $base = rtrim($issuer, '/');

        return [
            'issuer' => $base,
            'authorization_endpoint' => $base.Constants::OIDC_ROUTE_AUTHORIZE,
            'token_endpoint' => $base.Constants::OIDC_ROUTE_TOKEN,
            'jwks_uri' => $base.Constants::OIDC_ROUTE_JWKS,
            'userinfo_endpoint' => $base.Constants::OIDC_ROUTE_USERINFO,
            'response_types_supported' => [Constants::OIDC_RESPONSE_TYPE_CODE],
            'grant_types_supported' => [Constants::OIDC_GRANT_AUTHORIZATION_CODE],
            'code_challenge_methods_supported' => [Constants::OIDC_PKCE_METHOD_S256],
            'id_token_signing_alg_values_supported' => [Constants::OIDC_ALG_EDDSA],
            'token_endpoint_auth_methods_supported' => ['none'],
            'subject_types_supported' => [Constants::OIDC_SUBJECT_TYPE_PUBLIC],
            'scopes_supported' => [
                Constants::OIDC_SCOPE_OPENID,
                Constants::OIDC_SCOPE_PROFILE,
                Constants::OIDC_SCOPE_EMAIL,
            ],
            'claims_supported' => [
                'iss', 'sub', 'aud', 'exp', 'iat', 'jti', 'nonce',
                'customer_id', 'principal_id', 'principal_kind',
                'principal_members', 'surface', 'org_id', 'email', 'scope',
            ],
        ];
    }

    private function __construct() {}
}
