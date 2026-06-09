<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Auth\RoleResolver;
use Simplee\FredConnector\Constants;
use WP_User;

final class RoleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        if (function_exists('__test_clear_filters')) {
            __test_clear_filters();
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function user(array $roles): WP_User
    {
        return WP_User::make(7, 'user@example.com', $roles);
    }

    public function test_administrator_is_classified_as_admin_surface(): void
    {
        $surface = (new RoleResolver)->resolve($this->user([Constants::ROLE_ADMINISTRATOR]));

        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $surface);
    }

    public function test_shop_manager_is_classified_as_admin_surface(): void
    {
        $surface = (new RoleResolver)->resolve($this->user([Constants::ROLE_SHOP_MANAGER]));

        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $surface);
    }

    public function test_customer_is_classified_as_user_surface(): void
    {
        $surface = (new RoleResolver)->resolve($this->user([Constants::ROLE_CUSTOMER]));

        $this->assertSame(Constants::HANDOFF_SURFACE_USER, $surface);
    }

    public function test_subscriber_only_is_classified_as_user_surface(): void
    {
        $surface = (new RoleResolver)->resolve($this->user([Constants::ROLE_SUBSCRIBER]));

        $this->assertSame(Constants::HANDOFF_SURFACE_USER, $surface);
    }

    public function test_unknown_role_returns_null_so_handoff_is_refused(): void
    {
        $surface = (new RoleResolver)->resolve($this->user(['editor']));

        $this->assertNull($surface);
    }

    public function test_admin_role_takes_precedence_over_user_role_when_both_are_present(): void
    {
        $surface = (new RoleResolver)->resolve(
            $this->user([Constants::ROLE_CUSTOMER, Constants::ROLE_SHOP_MANAGER]),
        );

        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $surface);
    }

    public function test_filter_can_promote_an_unknown_role_to_admin_surface(): void
    {
        __test_register_filter(
            Constants::HANDOFF_FILTER_RESOLVE_SURFACE,
            static fn (mixed $current, WP_User $u, array $roles): string => Constants::HANDOFF_SURFACE_ADMIN,
        );

        $surface = (new RoleResolver)->resolve($this->user(['editor']));

        $this->assertSame(Constants::HANDOFF_SURFACE_ADMIN, $surface);
    }

    public function test_filter_returning_an_invalid_string_is_rejected_to_null(): void
    {
        __test_register_filter(
            Constants::HANDOFF_FILTER_RESOLVE_SURFACE,
            static fn (mixed $current): string => 'not-a-real-surface',
        );

        $surface = (new RoleResolver)->resolve($this->user(['editor']));

        $this->assertNull($surface);
    }
}
