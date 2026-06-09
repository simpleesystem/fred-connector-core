<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Auth;

use Simplee\FredConnector\Constants;
use WP_User;

/**
 * Default principal resolver.
 *
 * Emits a `user`-kind principal id of the form
 * `wp:<site_id>:user:<wp_user_id>` and exposes the result through the
 * `fred_cloud_resolve_principal` filter so multi-account plugins can
 * substitute a `group` or `account` principal without subclassing.
 *
 * The site id defaults to `1` (single-site WP) and falls back to the
 * blog id under multisite when `get_current_blog_id()` is available.
 * Tests can inject a fixed site id to avoid the global.
 */
class PrincipalResolver implements PrincipalResolverInterface
{
    private const PRINCIPAL_ID_FORMAT = 'wp:%d:user:%d';

    public function __construct(
        private readonly ?int $siteIdOverride = null,
    ) {}

    public function resolve(WP_User $user, string $customerId, ?string $orgId): HandoffPrincipal
    {
        $siteId = $this->siteIdOverride ?? $this->resolveSiteId();
        $principalId = sprintf(self::PRINCIPAL_ID_FORMAT, $siteId, $user->ID);
        $defaultPrincipal = new HandoffPrincipal(
            principalId: $principalId,
            principalKind: Constants::HANDOFF_PRINCIPAL_KIND_USER,
            members: [],
            customerId: $customerId,
            orgId: $orgId,
        );

        if (! function_exists('apply_filters')) {
            return $defaultPrincipal;
        }

        $filtered = apply_filters(
            Constants::HANDOFF_FILTER_RESOLVE_PRINCIPAL,
            $defaultPrincipal,
            $user,
            $customerId,
            $orgId,
        );

        return $filtered instanceof HandoffPrincipal ? $filtered : $defaultPrincipal;
    }

    private function resolveSiteId(): int
    {
        if (function_exists('get_current_blog_id')) {
            return (int) get_current_blog_id();
        }

        return 1;
    }
}
