<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Oidc;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Oidc\AuthorizationCodeStore;
use Simplee\FredConnector\Oidc\ResolvedIdentity;

final class AuthorizationCodeStoreTest extends TestCase
{
    private const FIXED_NOW = 1_700_000_000;

    private const FIXED_CODE = 'fixed-authorization-code-value';

    /**
     * @var array<string, mixed>
     */
    private array $transients = [];

    private int $now = self::FIXED_NOW;

    private function makeStore(): AuthorizationCodeStore
    {
        return new AuthorizationCodeStore(
            clock: fn (): int => $this->now,
            codeFactory: static fn (): string => self::FIXED_CODE,
            writeTransient: function (string $key, mixed $value, int $ttl): bool {
                $this->transients[$key] = $value;

                return true;
            },
            readTransient: fn (string $key): mixed => $this->transients[$key] ?? false,
            deleteTransient: function (string $key): bool {
                unset($this->transients[$key]);

                return true;
            },
        );
    }

    private function identity(): ResolvedIdentity
    {
        return new ResolvedIdentity(
            subject: 'wp:42:user:7',
            customerId: 'cust_abc',
            principalId: 'wp:42:user:7',
            principalKind: Constants::HANDOFF_PRINCIPAL_KIND_USER,
            principalMembers: [],
            surface: Constants::HANDOFF_SURFACE_ADMIN,
            orgId: null,
        );
    }

    private function issueOne(AuthorizationCodeStore $store): string
    {
        return $store->issue(
            clientId: 'portal',
            redirectUri: 'https://login.talktofred.ai/callback',
            codeChallenge: 'challenge',
            codeChallengeMethod: Constants::OIDC_PKCE_METHOD_S256,
            scope: 'openid',
            nonce: 'n-123',
            identity: $this->identity(),
        );
    }

    public function test_issue_then_consume_round_trips_the_record(): void
    {
        $store = $this->makeStore();
        $code = $this->issueOne($store);

        $record = $store->consume($code);

        $this->assertNotNull($record);
        $this->assertSame('portal', $record->clientId);
        $this->assertSame('https://login.talktofred.ai/callback', $record->redirectUri);
        $this->assertSame('challenge', $record->codeChallenge);
        $this->assertSame('n-123', $record->nonce);
        $this->assertSame('cust_abc', $record->identity->customerId);
        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $record->identity->surface);
    }

    public function test_codes_are_single_use(): void
    {
        $store = $this->makeStore();
        $code = $this->issueOne($store);

        $this->assertNotNull($store->consume($code));
        $this->assertNull($store->consume($code));
    }

    public function test_expired_codes_are_rejected(): void
    {
        $store = $this->makeStore();
        $code = $this->issueOne($store);

        $this->now = self::FIXED_NOW + Constants::OIDC_AUTH_CODE_TTL_SECONDS + 1;

        $this->assertNull($store->consume($code));
    }

    public function test_unknown_code_is_null(): void
    {
        $this->assertNull($this->makeStore()->consume('never-issued'));
    }
}
