<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Auth;

use WP_User;

/**
 * Pluggable resolver: WP user (+ ambient request) -> Fred Cloud principal.
 *
 * Default impl ({@see PrincipalResolver}) emits a `user` principal of
 * shape `wp:<site_id>:user:<wp_user_id>`. Multi-account plugins
 * (Memberships, B2BKing, WCFM, MemberPress) hook the
 * `fred_cloud_resolve_principal` filter (see Constants::HANDOFF_FILTER_RESOLVE_PRINCIPAL)
 * to override with their own `group`/`account` principals — no SDK
 * code change required per integration.
 */
interface PrincipalResolverInterface
{
    public function resolve(WP_User $user, string $customerId, ?string $orgId): HandoffPrincipal;
}
