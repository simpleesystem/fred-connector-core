<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use Simplee\FredConnector\Constants;

/**
 * Immutable OIDC signing key: an Ed25519 keypair plus its stable `kid`.
 *
 * `secretKey` is the 64-byte libsodium secret key (used to sign tokens);
 * `publicKey` is the 32-byte public key (published in the JWKS so Fred
 * Cloud can verify). EdDSA is chosen because libsodium is always present
 * on WP hosts and the cloud relying party verifies EdDSA natively.
 */
final class SigningKey
{
    public function __construct(
        public readonly string $kid,
        public readonly string $secretKey,
        public readonly string $publicKey,
    ) {}

    /**
     * Public JWK (RFC 8037 OKP/Ed25519) for the JWKS document.
     *
     * @return array<string, string>
     */
    public function publicJwk(): array
    {
        return [
            'kty' => Constants::OIDC_KTY_OKP,
            'crv' => Constants::OIDC_CRV_ED25519,
            'use' => Constants::OIDC_KEY_USE_SIG,
            'alg' => Constants::OIDC_ALG_EDDSA,
            'kid' => $this->kid,
            'x' => Base64Url::encode($this->publicKey),
        ];
    }
}
