<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\Discovery;

final class DiscoveryTest extends TestCase
{
    private const ISSUER = 'https://shop.test/wp-json/fred-cloud-oidc/v1';

    public function test_document_advertises_the_implemented_endpoints_and_capabilities(): void
    {
        $doc = Discovery::document(self::ISSUER.'/');

        $this->assertSame(self::ISSUER, $doc['issuer']);
        $this->assertSame(self::ISSUER.Constants::OIDC_ROUTE_AUTHORIZE, $doc['authorization_endpoint']);
        $this->assertSame(self::ISSUER.Constants::OIDC_ROUTE_TOKEN, $doc['token_endpoint']);
        $this->assertSame(self::ISSUER.Constants::OIDC_ROUTE_JWKS, $doc['jwks_uri']);
        $this->assertSame(self::ISSUER.Constants::OIDC_ROUTE_USERINFO, $doc['userinfo_endpoint']);
        $this->assertSame([Constants::OIDC_RESPONSE_TYPE_CODE], $doc['response_types_supported']);
        $this->assertSame([Constants::OIDC_GRANT_AUTHORIZATION_CODE], $doc['grant_types_supported']);
        $this->assertSame([Constants::OIDC_PKCE_METHOD_S256], $doc['code_challenge_methods_supported']);
        $this->assertSame([Constants::OIDC_ALG_EDDSA], $doc['id_token_signing_alg_values_supported']);
    }
}
