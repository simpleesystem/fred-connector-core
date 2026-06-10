<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Config;

/**
 * Single source of truth for the effective Fred Cloud base URL.
 *
 * The base URL is an optional override: sites normally leave the setting at
 * its registered default (the connector's production cloud URL, injected via
 * the constructor).
 *
 * WordPress only writes an option row when a saved value differs from the
 * `register_setting()` default, so a fresh install that never edits the
 * pre-filled base-URL field has NO base-URL option row at all. A naive
 * `get_option(KEY, '')` then returns '' and callers wrongly conclude the
 * connector is "not configured" — even with a valid service token and a
 * fully reachable cloud (the defect that pinned the connection notice on
 * "not connected yet" on fresh installs). Every base-URL read MUST therefore
 * default to — and fall back to — the production base URL, never the empty
 * string.
 *
 * The option key and default URL are connector-specific and injected by the
 * consumer; reads go through an injectable callable (defaulting to
 * `get_option`) so the resolver is unit-testable without WordPress.
 */
final class BaseUrlResolver
{
    /**
     * @var callable(string, mixed): mixed
     */
    private $readOption;

    /**
     * @param  string  $baseUrlOption  WP option key the connector stores its base-URL override under
     * @param  string  $defaultBaseUrl  the connector's production default base URL
     * @param  (callable(string, mixed): mixed)|null  $readOption
     */
    public function __construct(
        private readonly string $baseUrlOption,
        private readonly string $defaultBaseUrl,
        ?callable $readOption = null,
    ) {
        $this->readOption = $readOption ?? static function (string $key, mixed $default): mixed {
            return function_exists('get_option') ? get_option($key, $default) : $default;
        };
    }

    /**
     * Resolve the effective base URL: the saved override when present,
     * otherwise the production default. Always returns a non-empty URL with
     * any trailing slash trimmed.
     */
    public function resolve(): string
    {
        $baseUrl = (string) ($this->readOption)($this->baseUrlOption, $this->defaultBaseUrl);

        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            $baseUrl = rtrim($this->defaultBaseUrl, '/');
        }

        return $baseUrl;
    }
}
