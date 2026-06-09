<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Resources;

use Simplee\FredConnector\Constants;

/**
 * Immutable view of a resolved DoF bundle the SDK ships to Fred Cloud.
 *
 * The SDK builds these by merging four layers in order:
 *
 *   1. SDK-side defaults from the connector's configured default DoF bundle option
 *   2. tier-driven values from the local tier-to-DoF map (tier
 *      vocabulary lives entirely in the SDK admin)
 *   3. per-product DoF overrides set on the WC product meta
 *   4. per-order overrides if any (rare; reserved for one-off promotions)
 *
 * The cloud receives only the resolved DoF map — never tier names — and
 * applies its own hard ceilings before storing.
 */
final class QosBundle
{
    /**
     * @var array<string, mixed>
     */
    private readonly array $values;

    /**
     * @param  array<string, mixed>  $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function with(array $extra): self
    {
        return new self($extra + $this->values);
    }

    public function has(string $dof): bool
    {
        return array_key_exists($dof, $this->values);
    }

    public function get(string $dof, mixed $default = null): mixed
    {
        return $this->values[$dof] ?? $default;
    }

    public function int(string $dof, int $default = 0): int
    {
        $value = $this->values[$dof] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    public function bool(string $dof, bool $default = false): bool
    {
        $value = $this->values[$dof] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return $default;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $dof): array
    {
        $value = $this->values[$dof] ?? null;
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (! is_string($entry) || $entry === '') {
                continue;
            }
            if (! in_array($entry, Constants::PROVIDER_SLUGS, true)) {
                continue;
            }
            $out[] = $entry;
        }

        return array_values(array_unique($out));
    }

    public function isUnlimited(string $dof): bool
    {
        return $this->int($dof, 0) === Constants::QOS_UNLIMITED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}
