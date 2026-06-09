<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Resources;

/**
 * Cloud-side customer snapshot returned by every customer endpoint.
 *
 * Carries the resolved DoF bundle and the lifecycle state flags
 * (`suspended_at`, `frozen_at`) so the SDK admin UI can render an
 * accurate "current state" view without a second round trip.
 */
final class Customer
{
    public function __construct(
        public readonly string $customerId,
        public readonly ?string $displayName,
        public readonly QosBundle $dof,
        public readonly ?string $suspendedAt,
        public readonly ?string $frozenAt,
        public readonly ?string $rotatedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $dofRaw = is_array($payload['dof'] ?? null) ? $payload['dof'] : [];
        $customerIdRaw = $payload['customer_id'] ?? '';

        return new self(
            customerId: is_string($customerIdRaw) ? $customerIdRaw : '',
            displayName: is_string($payload['display_name'] ?? null) ? $payload['display_name'] : null,
            dof: new QosBundle($dofRaw),
            suspendedAt: is_string($payload['suspended_at'] ?? null) ? $payload['suspended_at'] : null,
            frozenAt: is_string($payload['frozen_at'] ?? null) ? $payload['frozen_at'] : null,
            rotatedAt: is_string($payload['rotated_at'] ?? null) ? $payload['rotated_at'] : null,
        );
    }

    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function isFrozen(): bool
    {
        return $this->frozenAt !== null;
    }
}
