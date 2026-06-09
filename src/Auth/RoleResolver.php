<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Auth;

use Simplee\FredConnector\Constants;
use WP_User;

/**
 * Maps a logged-in WP user to the Fred Cloud `surface` they may land on.
 *
 *   - `administrator`, `shop_manager`, `sva_agency_admin`, `sva_client_admin`
 *     -> `admin`
 *   - `customer`, `subscriber`             -> `user`
 *   - any other role                        -> `null` (refuse handoff)
 *
 * The `fred_cloud_resolve_surface` filter lets site owners override the
 * default mapping (e.g. promote a `editor` to `admin` for support
 * staff) without forking the SDK.
 */
class RoleResolver
{
    public function resolve(WP_User $user): ?string
    {
        $roles = array_values($user->roles);
        $default = $this->classify($roles);

        if (! function_exists('apply_filters')) {
            return $default;
        }

        $filtered = apply_filters(
            Constants::HANDOFF_FILTER_RESOLVE_SURFACE,
            $default,
            $user,
            $roles,
        );

        if ($filtered === null) {
            return null;
        }
        if (! is_string($filtered)) {
            return $default;
        }
        if ($filtered !== Constants::HANDOFF_SURFACE_ADMIN
            && $filtered !== Constants::HANDOFF_SURFACE_USER
        ) {
            return null;
        }

        return $filtered;
    }

    /**
     * @param  list<string>  $roles
     */
    private function classify(array $roles): ?string
    {
        foreach ($roles as $role) {
            if (in_array($role, Constants::ADMIN_SURFACE_ROLES, true)) {
                return Constants::HANDOFF_SURFACE_ADMIN;
            }
        }
        foreach ($roles as $role) {
            if (in_array($role, Constants::USER_SURFACE_ROLES, true)) {
                return Constants::HANDOFF_SURFACE_USER;
            }
        }

        return null;
    }
}
