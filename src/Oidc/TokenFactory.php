<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use DateTimeImmutable;
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder as TokenBuilder;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Simplee\FredConnector\Constants;
use Throwable;

/**
 * Mints the EdDSA-signed tokens the OIDC token endpoint returns.
 *
 * - **Access token**: the bearer Fred Cloud verifies. Its claim set is a
 *   deliberate drop-in for the cloud's retiring `Hs256JwtMinter` session
 *   token (`sub`, `customer_id`, `principal_*`, `surface`, surface-derived
 *   `scope`/`scp`), so the relying party accepts it unchanged — only the
 *   issuer and signature algorithm differ (external IdP, EdDSA).
 * - **ID token**: OIDC identity assertion for the client (`aud=client_id`,
 *   `nonce`).
 *
 * All tokens carry the signing key's `kid` header so the JWKS consumer
 * can select the right key.
 */
class TokenFactory
{
    /**
     * @var callable(): DateTimeImmutable
     */
    private $clock;

    /**
     * @var callable(): string
     */
    private $jtiFactory;

    private readonly Parser $parser;

    private readonly Validator $validator;

    /**
     * @param  (callable(): DateTimeImmutable)|null  $clock
     * @param  (callable(): string)|null  $jtiFactory
     */
    public function __construct(
        private readonly SigningKey $key,
        private readonly string $issuer,
        ?callable $clock = null,
        ?callable $jtiFactory = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable;
        $this->jtiFactory = $jtiFactory ?? static fn (): string => bin2hex(random_bytes(16));
        $this->parser = new Parser(new JoseEncoder);
        $this->validator = new Validator;
    }

    public function accessToken(
        ResolvedIdentity $identity,
        string $audience,
        int $ttlSeconds = Constants::OIDC_ACCESS_TOKEN_TTL_SECONDS,
    ): string {
        $now = ($this->clock)();
        $expires = $now->modify(sprintf('+%d seconds', max(1, $ttlSeconds)));
        $scopes = $this->scopesForSurface($identity->surface);

        $builder = $this->baseBuilder($now, $expires)
            ->relatedTo($identity->subject)
            ->permittedFor($audience)
            ->withClaim('customer_id', $identity->customerId)
            ->withClaim('principal_id', $identity->principalId)
            ->withClaim('principal_kind', $identity->principalKind)
            ->withClaim('surface', $identity->surface)
            ->withClaim('scope', implode(' ', $scopes))
            ->withClaim('scp', $scopes);

        if ($identity->principalMembers !== []) {
            $builder = $builder->withClaim('principal_members', array_values($identity->principalMembers));
        }
        if ($identity->orgId !== null && $identity->orgId !== '') {
            $builder = $builder->withClaim('org_id', $identity->orgId);
        }

        return $this->sign($builder);
    }

    public function idToken(
        ResolvedIdentity $identity,
        string $clientId,
        ?string $nonce,
        int $ttlSeconds = Constants::OIDC_ID_TOKEN_TTL_SECONDS,
    ): string {
        $now = ($this->clock)();
        $expires = $now->modify(sprintf('+%d seconds', max(1, $ttlSeconds)));

        $builder = $this->baseBuilder($now, $expires)
            ->relatedTo($identity->subject)
            ->permittedFor($clientId)
            ->withClaim('customer_id', $identity->customerId)
            ->withClaim('surface', $identity->surface);

        if ($nonce !== null && $nonce !== '') {
            $builder = $builder->withClaim('nonce', $nonce);
        }
        if ($identity->email !== null && $identity->email !== '') {
            $builder = $builder->withClaim('email', $identity->email);
        }

        return $this->sign($builder);
    }

    /**
     * Verify a token this factory issued (used by `/userinfo`). Checks
     * the EdDSA signature against our own public key plus issuer/expiry.
     *
     * @return array<string, mixed>|null  claims when valid, null otherwise
     */
    public function verifySelfIssued(string $rawToken): ?array
    {
        try {
            $parsed = $this->parser->parse($rawToken);
        } catch (Throwable) {
            return null;
        }
        if (! $parsed instanceof UnencryptedToken) {
            return null;
        }

        $signedWith = new SignedWith(new Eddsa, InMemory::plainText($this->key->publicKey));
        if (! $this->validator->validate($parsed, $signedWith)) {
            return null;
        }

        $issuer = $parsed->claims()->get('iss');
        if (! is_string($issuer) || $issuer !== $this->issuer) {
            return null;
        }

        $exp = $parsed->claims()->get('exp');
        if ($exp instanceof DateTimeImmutable && $exp < ($this->clock)()) {
            return null;
        }

        $claims = [];
        foreach ($parsed->claims()->all() as $name => $value) {
            $claims[$name] = $value instanceof DateTimeImmutable ? $value->getTimestamp() : $value;
        }

        return $claims;
    }

    private function baseBuilder(DateTimeImmutable $now, DateTimeImmutable $expires): Builder
    {
        return (new TokenBuilder(new JoseEncoder, ChainedFormatter::default()))
            ->withHeader('kid', $this->key->kid)
            ->issuedBy($this->issuer)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($expires)
            ->identifiedBy(($this->jtiFactory)());
    }

    private function sign(Builder $builder): string
    {
        return $builder->getToken(new Eddsa, InMemory::plainText($this->key->secretKey))->toString();
    }

    /**
     * @return list<string>
     */
    private function scopesForSurface(string $surface): array
    {
        return $surface === Constants::HANDOFF_SURFACE_ADMIN
            ? Constants::OIDC_DEFAULT_ADMIN_SCOPES
            : Constants::OIDC_DEFAULT_USER_SCOPES;
    }
}
