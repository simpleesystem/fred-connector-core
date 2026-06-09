<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

/**
 * A registered OIDC client (relying party). The login portal SPA is a
 * public client (no secret) authenticating via authorization-code+PKCE.
 *
 * `audience` is the resource the issued access token is minted for (the
 * Fred Cloud adapter). `redirectUris` is an exact-match allow-list — no
 * wildcard/substring matching, per OAuth 2.1.
 */
final class OidcClient
{
    /**
     * @param  list<string>  $redirectUris
     * @param  list<string>  $scopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly array $redirectUris,
        public readonly bool $requirePkce,
        public readonly string $audience,
        public readonly array $scopes,
    ) {}

    public function allowsRedirectUri(string $redirectUri): bool
    {
        return $redirectUri !== '' && in_array($redirectUri, $this->redirectUris, true);
    }
}
