<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\Base64Url;
use Simplee\FredConnector\Oidc\Pkce;

final class PkceTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

    private function challengeFor(string $verifier): string
    {
        return Base64Url::encode(hash('sha256', $verifier, true));
    }

    public function test_verifies_a_correct_s256_challenge(): void
    {
        $challenge = $this->challengeFor(self::VERIFIER);

        $this->assertTrue(Pkce::verify(self::VERIFIER, $challenge, Constants::OIDC_PKCE_METHOD_S256));
    }

    public function test_rejects_a_mismatched_verifier(): void
    {
        $challenge = $this->challengeFor(self::VERIFIER);

        $this->assertFalse(Pkce::verify('the-wrong-verifier', $challenge, Constants::OIDC_PKCE_METHOD_S256));
    }

    public function test_rejects_the_plain_method(): void
    {
        $this->assertFalse(Pkce::verify(self::VERIFIER, self::VERIFIER, 'plain'));
        $this->assertFalse(Pkce::methodSupported('plain'));
    }

    public function test_rejects_empty_inputs(): void
    {
        $this->assertFalse(Pkce::verify('', $this->challengeFor(self::VERIFIER), Constants::OIDC_PKCE_METHOD_S256));
        $this->assertFalse(Pkce::verify(self::VERIFIER, '', Constants::OIDC_PKCE_METHOD_S256));
    }
}
