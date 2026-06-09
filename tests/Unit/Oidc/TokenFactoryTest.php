<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use DateTimeImmutable;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\ResolvedIdentity;
use Simplee\FredConnector\Oidc\SigningKey;
use Simplee\FredConnector\Oidc\TokenFactory;

final class TokenFactoryTest extends TestCase
{
    private const ISSUER = 'https://shop.test/wp-json/fred-cloud-oidc/v1';

    private const AUDIENCE = 'fred-adapter';

    private const CLIENT_ID = 'portal';

    private SigningKey $key;

    protected function setUp(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $this->key = new SigningKey(
            'kid-test',
            sodium_crypto_sign_secretkey($keypair),
            sodium_crypto_sign_publickey($keypair),
        );
    }

    private function factory(): TokenFactory
    {
        return new TokenFactory(
            key: $this->key,
            issuer: self::ISSUER,
            clock: static fn (): DateTimeImmutable => new DateTimeImmutable('@1700000000'),
            jtiFactory: static fn (): string => 'jti-fixed',
        );
    }

    private function identity(string $surface): ResolvedIdentity
    {
        return new ResolvedIdentity(
            subject: 'wp:42:user:7',
            customerId: 'cust_abc',
            principalId: 'wp:42:user:7',
            principalKind: Constants::HANDOFF_PRINCIPAL_KIND_USER,
            principalMembers: [],
            surface: $surface,
            orgId: 'org_acme',
            email: 'admin@shop.test',
        );
    }

    private function parse(string $jwt): UnencryptedToken
    {
        $token = (new Parser(new JoseEncoder))->parse($jwt);
        $this->assertInstanceOf(UnencryptedToken::class, $token);

        return $token;
    }

    /**
     * Verifies the EdDSA signature exactly the way the cloud relying
     * party does (public key only) — proving cross-repo acceptance.
     */
    private function signatureValid(UnencryptedToken $token): bool
    {
        return (new Validator)->validate(
            $token,
            new SignedWith(new Eddsa, InMemory::plainText($this->key->publicKey)),
        );
    }

    public function test_access_token_is_eddsa_signed_and_carries_cloud_parity_claims(): void
    {
        $jwt = $this->factory()->accessToken($this->identity(Constants::HANDOFF_SURFACE_ADMIN), self::AUDIENCE);
        $token = $this->parse($jwt);

        $this->assertTrue($this->signatureValid($token));
        $this->assertSame('kid-test', $token->headers()->get('kid'));
        $this->assertSame(Constants::OIDC_ALG_EDDSA, $token->headers()->get('alg'));
        $this->assertSame(self::ISSUER, $token->claims()->get('iss'));
        $this->assertSame('wp:42:user:7', $token->claims()->get('sub'));
        $this->assertSame([self::AUDIENCE], $token->claims()->get('aud'));
        $this->assertSame('cust_abc', $token->claims()->get('customer_id'));
        $this->assertSame('wp:42:user:7', $token->claims()->get('principal_id'));
        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $token->claims()->get('surface'));
        $this->assertSame('org_acme', $token->claims()->get('org_id'));
    }

    public function test_admin_and_user_surfaces_get_their_scope_bundles(): void
    {
        $admin = $this->parse($this->factory()->accessToken($this->identity(Constants::HANDOFF_SURFACE_ADMIN), self::AUDIENCE));
        $user = $this->parse($this->factory()->accessToken($this->identity(Constants::HANDOFF_SURFACE_USER), self::AUDIENCE));

        $this->assertSame(Constants::OIDC_DEFAULT_ADMIN_SCOPES, $admin->claims()->get('scp'));
        $this->assertSame(Constants::OIDC_DEFAULT_USER_SCOPES, $user->claims()->get('scp'));
        $this->assertStringContainsString('fred:admin:*', (string) $admin->claims()->get('scope'));
        $this->assertStringContainsString('fred:user:*', (string) $user->claims()->get('scope'));
    }

    public function test_id_token_is_audienced_to_the_client_and_carries_nonce(): void
    {
        $jwt = $this->factory()->idToken($this->identity(Constants::HANDOFF_SURFACE_USER), self::CLIENT_ID, 'nonce-xyz');
        $token = $this->parse($jwt);

        $this->assertTrue($this->signatureValid($token));
        $this->assertSame([self::CLIENT_ID], $token->claims()->get('aud'));
        $this->assertSame('nonce-xyz', $token->claims()->get('nonce'));
        $this->assertSame('admin@shop.test', $token->claims()->get('email'));
    }

    public function test_verify_self_issued_accepts_a_fresh_access_token(): void
    {
        $factory = $this->factory();
        $jwt = $factory->accessToken($this->identity(Constants::HANDOFF_SURFACE_ADMIN), self::AUDIENCE);

        $claims = $factory->verifySelfIssued($jwt);

        $this->assertIsArray($claims);
        $this->assertSame('cust_abc', $claims['customer_id']);
    }

    public function test_verify_self_issued_rejects_a_foreign_signature(): void
    {
        $jwt = $this->factory()->accessToken($this->identity(Constants::HANDOFF_SURFACE_ADMIN), self::AUDIENCE);

        $otherKeypair = sodium_crypto_sign_keypair();
        $otherFactory = new TokenFactory(
            key: new SigningKey('other', sodium_crypto_sign_secretkey($otherKeypair), sodium_crypto_sign_publickey($otherKeypair)),
            issuer: self::ISSUER,
        );

        $this->assertNull($otherFactory->verifySelfIssued($jwt));
    }
}
