<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\ClientRegistry;

final class ClientRegistryTest extends TestCase
{
    private const PORTAL_REDIRECT = 'https://login.talktofred.ai/callback';

    private const CUSTOM_REDIRECT = 'https://login.example.test/v1/auth/oidc/callback';

    private const FALSY_OPTION_VALUE = '0';

    // Connector-supplied option keys (injected; the shared package no longer
    // owns an OptionKeys class). Any stable strings work for the contract.
    private const OPT_CLIENTS = 'oidc_clients';

    private const OPT_PORTAL_ENABLED = 'oidc_login_portal_enabled';

    private const OPT_PORTAL_REDIRECT = 'oidc_login_portal_redirect_uri';

    private function registryFromJson(string $json): ClientRegistry
    {
        return new ClientRegistry(
            self::OPT_CLIENTS,
            self::OPT_PORTAL_ENABLED,
            self::OPT_PORTAL_REDIRECT,
            readOption: static fn (string $key): mixed => $json,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function registryFromOptions(array $options): ClientRegistry
    {
        return new ClientRegistry(
            self::OPT_CLIENTS,
            self::OPT_PORTAL_ENABLED,
            self::OPT_PORTAL_REDIRECT,
            readOption: static fn (string $key): mixed => $options[$key] ?? '',
        );
    }

    public function test_resolves_a_client_from_json_option(): void
    {
        $registry = $this->registryFromJson((string) json_encode([
            ['client_id' => 'portal', 'redirect_uris' => [self::PORTAL_REDIRECT], 'audience' => 'fred-adapter'],
        ]));

        $client = $registry->find('portal');

        $this->assertNotNull($client);
        $this->assertSame('portal', $client->clientId);
        $this->assertSame('fred-adapter', $client->audience);
        $this->assertTrue($client->requirePkce);
        $this->assertTrue($client->allowsRedirectUri(self::PORTAL_REDIRECT));
    }

    public function test_redirect_uri_match_is_exact(): void
    {
        $registry = $this->registryFromJson((string) json_encode([
            ['client_id' => 'portal', 'redirect_uris' => [self::PORTAL_REDIRECT]],
        ]));
        $client = $registry->find('portal');

        $this->assertNotNull($client);
        $this->assertFalse($client->allowsRedirectUri(self::PORTAL_REDIRECT.'/evil'));
        $this->assertFalse($client->allowsRedirectUri('https://login.talktofred.ai'));
    }

    public function test_unknown_client_is_null(): void
    {
        $registry = $this->registryFromJson((string) json_encode([
            ['client_id' => 'portal', 'redirect_uris' => [self::PORTAL_REDIRECT]],
        ]));

        $this->assertNull($registry->find('nope'));
        $this->assertNull($registry->find(''));
    }

    public function test_defaults_audience_when_unspecified(): void
    {
        $registry = new ClientRegistry(
            self::OPT_CLIENTS,
            self::OPT_PORTAL_ENABLED,
            self::OPT_PORTAL_REDIRECT,
            readOption: static fn (string $key): mixed => (string) json_encode([
                ['client_id' => 'portal', 'redirect_uris' => [self::PORTAL_REDIRECT]],
            ]),
            defaultAudience: Constants::OIDC_DEFAULT_API_AUDIENCE,
        );

        $client = $registry->find('portal');

        $this->assertNotNull($client);
        $this->assertSame(Constants::OIDC_DEFAULT_API_AUDIENCE, $client->audience);
    }

    public function test_registers_the_first_party_login_portal_client_by_default(): void
    {
        $registry = $this->registryFromOptions([]);

        $client = $registry->find(Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT);

        $this->assertNotNull($client);
        $this->assertTrue($client->allowsRedirectUri(Constants::OIDC_LOGIN_PORTAL_REDIRECT_URI_DEFAULT));
    }

    public function test_login_portal_client_requires_pkce_by_default(): void
    {
        $registry = $this->registryFromOptions([]);

        $client = $registry->find(Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT);

        $this->assertNotNull($client);
        $this->assertTrue($client->requirePkce);
    }

    public function test_login_portal_client_can_be_disabled(): void
    {
        $registry = $this->registryFromOptions([
            self::OPT_PORTAL_ENABLED => self::FALSY_OPTION_VALUE,
        ]);

        $this->assertNull($registry->find(Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT));
    }

    public function test_login_portal_redirect_uri_is_overridable(): void
    {
        $registry = $this->registryFromOptions([
            self::OPT_PORTAL_REDIRECT => self::CUSTOM_REDIRECT,
        ]);

        $client = $registry->find(Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT);

        $this->assertNotNull($client);
        $this->assertTrue($client->allowsRedirectUri(self::CUSTOM_REDIRECT));
    }

    public function test_configured_client_overrides_the_default_for_the_same_id(): void
    {
        $registry = $this->registryFromOptions([
            self::OPT_CLIENTS => (string) json_encode([
                [
                    'client_id' => Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT,
                    'redirect_uris' => [self::CUSTOM_REDIRECT],
                ],
            ]),
        ]);

        $client = $registry->find(Constants::OIDC_LOGIN_PORTAL_CLIENT_ID_DEFAULT);

        $this->assertNotNull($client);
        $this->assertFalse($client->allowsRedirectUri(Constants::OIDC_LOGIN_PORTAL_REDIRECT_URI_DEFAULT));
    }
}
