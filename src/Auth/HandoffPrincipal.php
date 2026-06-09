<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Auth;

/**
 * Result of resolving the WP user (and any multi-account context) into
 * the principal a Fred Cloud session should be bound to.
 *
 * `principalKind` distinguishes:
 *   - `user`    — single WP user, `members === []`
 *   - `group`   — group/team identifier; `members` enumerates the WP
 *                 user IDs the bearer is allowed to act for
 *   - `account` — corporate account id; same shape as `group`
 *
 * `customerId` is the Fred Cloud customer the principal billing rolls
 * up to. Multiple principals can share the same `customerId` (an
 * agency with N seats), and a single principal can rotate between
 * customer IDs over time.
 */
final class HandoffPrincipal
{
    /**
     * @param  list<string>  $members
     */
    public function __construct(
        public readonly string $principalId,
        public readonly string $principalKind,
        public readonly array $members,
        public readonly string $customerId,
        public readonly ?string $orgId,
    ) {}
}
