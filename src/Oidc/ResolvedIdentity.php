<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

/**
 * The end-user identity resolved at the authorize step, carried through
 * the authorization code and stamped into the issued tokens.
 *
 * Mirrors the claim set Fred Cloud expects (`sub`, `customer_id`,
 * `principal_*`, `surface`, `org_id`) so OIDC tokens are a drop-in for
 * the retiring handoff session tokens.
 */
final class ResolvedIdentity
{
    /**
     * @param  list<string>  $principalMembers
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $customerId,
        public readonly string $principalId,
        public readonly string $principalKind,
        public readonly array $principalMembers,
        public readonly string $surface,
        public readonly ?string $orgId,
        public readonly ?string $email = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'customer_id' => $this->customerId,
            'principal_id' => $this->principalId,
            'principal_kind' => $this->principalKind,
            'principal_members' => $this->principalMembers,
            'surface' => $this->surface,
            'org_id' => $this->orgId,
            'email' => $this->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $members = [];
        if (isset($data['principal_members']) && is_array($data['principal_members'])) {
            foreach ($data['principal_members'] as $member) {
                if (is_string($member)) {
                    $members[] = $member;
                }
            }
        }

        $orgId = isset($data['org_id']) && is_string($data['org_id']) && $data['org_id'] !== '' ? $data['org_id'] : null;
        $email = isset($data['email']) && is_string($data['email']) && $data['email'] !== '' ? $data['email'] : null;

        return new self(
            subject: isset($data['subject']) && is_string($data['subject']) ? $data['subject'] : '',
            customerId: isset($data['customer_id']) && is_string($data['customer_id']) ? $data['customer_id'] : '',
            principalId: isset($data['principal_id']) && is_string($data['principal_id']) ? $data['principal_id'] : '',
            principalKind: isset($data['principal_kind']) && is_string($data['principal_kind']) ? $data['principal_kind'] : '',
            principalMembers: $members,
            surface: isset($data['surface']) && is_string($data['surface']) ? $data['surface'] : '',
            orgId: $orgId,
            email: $email,
        );
    }
}
