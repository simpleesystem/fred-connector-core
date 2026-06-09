<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\Base64Url;
use Simplee\FredConnector\Oidc\SigningKeyStore;

final class SigningKeyStoreTest extends TestCase
{
    // Connector-supplied option key (injected; the shared package no longer
    // owns an OptionKeys class).
    private const OPT_SIGNING_KEY = 'oidc_signing_key';

    /**
     * @var array<string, mixed>
     */
    private array $store = [];

    private function makeStore(): SigningKeyStore
    {
        return new SigningKeyStore(
            self::OPT_SIGNING_KEY,
            readOption: fn (string $key): mixed => $this->store[$key] ?? '',
            writeOption: function (string $key, mixed $value): bool {
                $this->store[$key] = $value;

                return true;
            },
        );
    }

    public function test_generates_and_persists_a_keypair_on_first_use(): void
    {
        $store = $this->makeStore();

        $key = $store->current();

        $this->assertNotSame('', $key->kid);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen($key->secretKey));
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, strlen($key->publicKey));
        $this->assertArrayHasKey(self::OPT_SIGNING_KEY, $this->store);
    }

    public function test_reloads_the_same_key_from_persisted_material(): void
    {
        $first = $this->makeStore()->current();

        $reloaded = $this->makeStore()->current();

        $this->assertSame($first->kid, $reloaded->kid);
        $this->assertSame($first->publicKey, $reloaded->publicKey);
    }

    public function test_public_jwk_has_okp_ed25519_shape(): void
    {
        $key = $this->makeStore()->current();

        $jwk = $key->publicJwk();

        $this->assertSame(Constants::OIDC_KTY_OKP, $jwk['kty']);
        $this->assertSame(Constants::OIDC_CRV_ED25519, $jwk['crv']);
        $this->assertSame(Constants::OIDC_ALG_EDDSA, $jwk['alg']);
        $this->assertSame($key->kid, $jwk['kid']);
        $this->assertSame($key->publicKey, Base64Url::decode($jwk['x']));
    }
}
