<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use RuntimeException;
use Throwable;

/**
 * Loads (or lazily generates and persists) the OIDC Ed25519 signing key.
 *
 * The keypair lives in the WP options table as `{kid, secret_b64}`. On
 * first use a fresh keypair is generated with libsodium; the `kid` is a
 * short, stable fingerprint of the public key so JWKS consumers can cache
 * by key id and rotation (clearing the option) yields a new `kid`.
 *
 * Persistence goes through injectable read/write callables so the store
 * is unit-testable without WordPress; they default to `get_option` /
 * `update_option`.
 */
class SigningKeyStore
{
    /**
     * @var callable(string): mixed
     */
    private $readOption;

    /**
     * @var callable(string, mixed): bool
     */
    private $writeOption;

    private ?SigningKey $cached = null;

    private readonly string $signingKeyOption;

    /**
     * @param  string  $signingKeyOption  WP option key the connector stores its OIDC signing key under
     * @param  (callable(string): mixed)|null  $readOption
     * @param  (callable(string, mixed): bool)|null  $writeOption
     */
    public function __construct(string $signingKeyOption, ?callable $readOption = null, ?callable $writeOption = null)
    {
        $this->signingKeyOption = $signingKeyOption;
        $this->readOption = $readOption ?? static function (string $key): mixed {
            return function_exists('get_option') ? get_option($key, '') : '';
        };
        $this->writeOption = $writeOption ?? static function (string $key, mixed $value): bool {
            return function_exists('update_option') ? (bool) update_option($key, $value) : false;
        };
    }

    public function current(): SigningKey
    {
        if ($this->cached instanceof SigningKey) {
            return $this->cached;
        }

        $existing = $this->loadExisting();
        if ($existing instanceof SigningKey) {
            return $this->cached = $existing;
        }

        return $this->cached = $this->generateAndPersist();
    }

    private function loadExisting(): ?SigningKey
    {
        $raw = ($this->readOption)($this->signingKeyOption);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $kid = isset($decoded['kid']) && is_string($decoded['kid']) ? $decoded['kid'] : '';
        $secretB64 = isset($decoded['secret_b64']) && is_string($decoded['secret_b64']) ? $decoded['secret_b64'] : '';
        if ($kid === '' || $secretB64 === '') {
            return null;
        }

        $secretKey = base64_decode($secretB64, true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            return null;
        }

        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);

        return new SigningKey($kid, $secretKey, $publicKey);
    }

    private function generateAndPersist(): SigningKey
    {
        try {
            $keypair = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($keypair);
            $publicKey = sodium_crypto_sign_publickey($keypair);
        } catch (Throwable $e) {
            throw new RuntimeException(
                sprintf('Unable to generate OIDC signing key: %s', $e->getMessage()),
                0,
                $e,
            );
        }

        $kid = $this->deriveKid($publicKey);

        ($this->writeOption)($this->signingKeyOption, (string) json_encode([
            'kid' => $kid,
            'secret_b64' => base64_encode($secretKey),
        ]));

        return new SigningKey($kid, $secretKey, $publicKey);
    }

    /**
     * Stable, collision-resistant key id derived from the public key.
     */
    private function deriveKid(string $publicKey): string
    {
        return substr(Base64Url::encode(hash('sha256', $publicKey, true)), 0, 16);
    }
}
