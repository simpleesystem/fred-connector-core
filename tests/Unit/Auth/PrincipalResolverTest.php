<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Auth\HandoffPrincipal;
use Simplee\FredConnector\Auth\PrincipalResolver;
use Simplee\FredConnector\Constants;
use WP_User;

final class PrincipalResolverTest extends TestCase
{
    private const SITE_ID = 42;

    private const CUSTOMER_ID = 'cust_abc';

    private const ORG_ID = 'org_acme';

    protected function setUp(): void
    {
        if (function_exists('__test_clear_filters')) {
            __test_clear_filters();
        }
    }

    private function user(int $id = 7): WP_User
    {
        return WP_User::make($id, 'user@example.com', [Constants::ROLE_CUSTOMER]);
    }

    public function test_default_resolution_emits_a_user_principal_with_the_canonical_id_format(): void
    {
        $principal = (new PrincipalResolver(self::SITE_ID))->resolve(
            $this->user(),
            self::CUSTOMER_ID,
            self::ORG_ID,
        );

        $this->assertSame('wp:42:user:7', $principal->principalId);
        $this->assertSame(Constants::HANDOFF_PRINCIPAL_KIND_USER, $principal->principalKind);
        $this->assertSame([], $principal->members);
        $this->assertSame(self::CUSTOMER_ID, $principal->customerId);
        $this->assertSame(self::ORG_ID, $principal->orgId);
    }

    public function test_default_resolution_falls_back_to_blog_id_when_no_override_is_provided(): void
    {
        $GLOBALS['__wp_current_blog_id'] = 9;

        $principal = (new PrincipalResolver)->resolve(
            $this->user(),
            self::CUSTOMER_ID,
            null,
        );

        $this->assertSame('wp:9:user:7', $principal->principalId);
    }

    public function test_filter_can_substitute_a_group_principal(): void
    {
        $expected = new HandoffPrincipal(
            principalId: 'wp:42:group:engineering',
            principalKind: Constants::HANDOFF_PRINCIPAL_KIND_GROUP,
            members: ['wp:42:user:7', 'wp:42:user:9'],
            customerId: self::CUSTOMER_ID,
            orgId: self::ORG_ID,
        );

        __test_register_filter(
            Constants::HANDOFF_FILTER_RESOLVE_PRINCIPAL,
            static fn (HandoffPrincipal $default, WP_User $u, string $cid, ?string $oid): HandoffPrincipal => $expected,
        );

        $principal = (new PrincipalResolver(self::SITE_ID))->resolve(
            $this->user(),
            self::CUSTOMER_ID,
            self::ORG_ID,
        );

        $this->assertSame($expected, $principal);
    }

    public function test_filter_returning_a_non_principal_value_falls_back_to_the_default(): void
    {
        __test_register_filter(
            Constants::HANDOFF_FILTER_RESOLVE_PRINCIPAL,
            static fn (HandoffPrincipal $default): string => 'not a principal',
        );

        $principal = (new PrincipalResolver(self::SITE_ID))->resolve(
            $this->user(),
            self::CUSTOMER_ID,
            null,
        );

        $this->assertSame('wp:42:user:7', $principal->principalId);
        $this->assertSame(Constants::HANDOFF_PRINCIPAL_KIND_USER, $principal->principalKind);
    }
}
