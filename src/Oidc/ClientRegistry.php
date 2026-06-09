<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use Simplee\FredConnector\Constants;

/**
 * Resolves registered OIDC clients.
 *
 * Clients are sourced from the `OIDC_CLIENTS` option (JSON array) and may
 * be extended/replaced at runtime via the `fred_cloud_oidc_clients`
 * filter (so the login portal client can be registered programmatically
 * without editing the DB). Each entry:
 *
 *   {client_id, redirect_uris[], require_pkce?, audience?, scopes?[]}
 */
class ClientRegistry
{
    /**
     * @var callable(string): mixed
     */
    private $readOption;

    private readonly string $defaultAudience;

    private readonly string $clientsOption;

    private readonly string $loginPortalEnabledOption;

    private readonly string $loginPortalRedirectUriOption;

    /**
     * @param  string  $clientsOption  WP option key holding the JSON client list
     * @param  string  $loginPortalEnabledOption  WP option key toggling the first-party login portal client
     * @param  string  $loginPortalRedirectUriOption  WP option key holding the login portal redirect URI override
     * @param  (callable(string): mixed)|null  $readOption
     */
    public function __construct(
        string $clientsOption,
        string $loginPortalEnabledOption,
        string $loginPortalRedirectUriOption,
        ?callable $readOption = null,
        ?string $defaultAudience = null,
    ) {
        $this->clientsOption = $clientsOption;
        $this->loginPortalEnabledOption = $loginPortalEnabledOption;
        $this->loginPortalRedirectUriOption = $loginPortalRedirectUriOption;
        $this->readOption = $readOption ?? static function (string $key): mixed {
            return function_exists('get_option') ? get_option($key, '') : '';
        };
        $this->defaultAudience = $defaultAudience ?? Constants::OIDC_DEFAULT_API_AUDIENCE;
    }

    public function find(string $clientId): ?OidcClient
    {
        if ($clientId === '') {
            return null;
        }
        foreach ($this->all() as $client) {
            if ($client->clientId === $clientId) {
                return $client;
            }
        }

        return null;
    }

    /**
     * @return list<OidcClient>
     */
    public function all(): array
    {
        // Explicitly-configured clients come first so they win on a
        // client_id collision; the first-party login portal default is
        // appended as a fallback. The filter still sees (and can prune)
        // the whole set.
        $entries = array_merge($this->rawEntries(), $this->defaultEntries());
        if (function_exists('apply_filters')) {
            $filtered = apply_filters(Constants::OIDC_FILTER_CLIENTS, $entries);
            if (is_array($filtered)) {
                $entries = $filtered;
            }
        }

        $clients = [];
        $seen = [];
        foreach ($entries as $entry) {
            $client = $this->hydrate($entry);
            if (! $client instanceof OidcClient) {
                continue;
            }
            if (isset($seen[$client->clientId])) {
                continue;
            }
            $seen[$client->clientId] = true;
            $clients[] = $client;
        }

        return $clients;
    }

    /**
     * The auto-registered first-party login portal client, unless
     * suppressed via the OIDC_LOGIN_PORTAL_ENABLED option.
     *
     * @return list<array{client_id: string, redirect_uris: list<string>, require_pkce: bool, scopes: list<string>}>
     */
    private function defaultEntries(): array
    {
        $enabledRaw = ($this->readOption)($this->loginPortalEnabledOption);
        $enabled = ($enabledRaw === '' || $enabledRaw === null)
            ? Constants::OIDC_LOGIN_PORTAL_DEFAULT_ENABLED
            : filter_var($enabledRaw, FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            return [];
        }

        $redirect = ($this->readOption)($this->loginPortalRedirectUriOption);
        if (! is_string($redirect) || $redirect === '') {
            $redirect = Constants::OIDC_LOGIN_PORTAL_REDIRECT_URI_DEFAULT;
        }

        return [
            [
                'client_id' => Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT,
                'redirect_uris' => [$redirect],
                'require_pkce' => true,
                'scopes' => [
                    Constants::OIDC_SCOPE_OPENID,
                    Constants::OIDC_SCOPE_PROFILE,
                    Constants::OIDC_SCOPE_EMAIL,
                ],
            ],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function rawEntries(): array
    {
        $raw = ($this->readOption)($this->clientsOption);
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_values($decoded);
            }
        }

        return [];
    }

    private function hydrate(mixed $entry): ?OidcClient
    {
        if (! is_array($entry)) {
            return null;
        }
        $clientId = isset($entry['client_id']) && is_string($entry['client_id']) ? $entry['client_id'] : '';
        if ($clientId === '') {
            return null;
        }

        $redirectUris = [];
        if (isset($entry['redirect_uris']) && is_array($entry['redirect_uris'])) {
            foreach ($entry['redirect_uris'] as $uri) {
                if (is_string($uri) && $uri !== '') {
                    $redirectUris[] = $uri;
                }
            }
        }

        $scopes = [];
        if (isset($entry['scopes']) && is_array($entry['scopes'])) {
            foreach ($entry['scopes'] as $scope) {
                if (is_string($scope) && $scope !== '') {
                    $scopes[] = $scope;
                }
            }
        }

        $audience = isset($entry['audience']) && is_string($entry['audience']) && $entry['audience'] !== ''
            ? $entry['audience']
            : $this->defaultAudience;

        $requirePkce = ! isset($entry['require_pkce']) || (bool) $entry['require_pkce'];

        return new OidcClient(
            clientId: $clientId,
            redirectUris: $redirectUris,
            requirePkce: $requirePkce,
            audience: $audience,
            scopes: $scopes,
        );
    }
}
