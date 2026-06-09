<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Update;

use Simplee\FredConnector\Constants;

/**
 * WordPress self-update channel backed by the Simple License Server.
 *
 * Mirrors the SLS update flow used by every other licensed Simplee plugin:
 * on each WordPress update-transient refresh the plugin asks SLS
 * `/updates/check` whether a newer build of its product slug exists, and
 * when WordPress starts the update it fetches a license-gated REST proxy
 * URL that 302s to a short-lived signed download from SLS
 * `/downloads/authorize`.
 *
 * This connector is a free/unprotected product, so the channel runs
 * keyless: it sends only `slug` + `domain` + `current_version` (+ the
 * `is_prerelease` opt-in) and omits `license_key` entirely. SLS recognizes
 * the slug as a free product, bypasses license validation and domain
 * activation, and returns a self-contained signed `download_url` inside
 * `update` — so no separate `/downloads/authorize` round trip is required.
 * A license key is still accepted (kept for drop-in compatibility) and, when
 * present, the legacy license-gated authorize path is used instead.
 *
 * All WordPress collaborators (HTTP, transient cache) are injected so the
 * protocol logic is unit-testable without a live WordPress request
 * lifecycle; {@see registerUpdateHooks()} adapts the testable methods to the
 * WordPress filter/action surface.
 *
 * @phpstan-type UpdateResult array{update: array<string, mixed>|null, update_available: bool, latest_version: string|null}
 */
final class PluginUpdateChecker
{
    private readonly string $baseUrl;

    private readonly string $transientPrefix;

    /**
     * @var callable(string, array<string, mixed>): (array<string, mixed>|null)
     */
    private $httpPost;

    /**
     * @var callable(string): mixed
     */
    private $getTransient;

    /**
     * @var callable(string, mixed, int): bool
     */
    private $setTransient;

    /**
     * @var callable(string): bool
     */
    private $deleteTransient;

    /**
     * @var callable(): string
     */
    private $domainResolver;

    /**
     * @param  (callable(string, array<string, mixed>): (array<string, mixed>|null))|null  $httpPost  POST JSON, decode response (null on failure)
     * @param  (callable(string): mixed)|null  $getTransient
     * @param  (callable(string, mixed, int): bool)|null  $setTransient
     * @param  (callable(string): bool)|null  $deleteTransient
     * @param  (callable(): string)|null  $domainResolver  fallback domain when none configured
     */
    public function __construct(
        private readonly string $pluginSlug,
        private readonly string $pluginFile,
        private readonly string $currentVersion,
        string $baseUrl,
        private readonly string $licenseKey = '',
        private readonly string $domain = '',
        private readonly bool $includePrerelease = false,
        ?callable $httpPost = null,
        ?callable $getTransient = null,
        ?callable $setTransient = null,
        ?callable $deleteTransient = null,
        ?callable $domainResolver = null,
        ?string $transientPrefix = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transientPrefix = ($transientPrefix === null || $transientPrefix === '')
            ? Constants::UPDATE_TRANSIENT_PREFIX
            : $transientPrefix;
        $this->httpPost = $httpPost ?? self::defaultHttpPost();
        $this->getTransient = $getTransient ?? static fn (string $key): mixed => function_exists('get_transient') ? get_transient($key) : false;
        $this->setTransient = $setTransient ?? static fn (string $key, mixed $value, int $ttl): bool => function_exists('set_transient') && (bool) set_transient($key, $value, $ttl);
        $this->deleteTransient = $deleteTransient ?? static fn (string $key): bool => function_exists('delete_transient') && (bool) delete_transient($key);
        $this->domainResolver = $domainResolver ?? static function (): string {
            if (function_exists('wp_parse_url') && function_exists('home_url')) {
                $host = wp_parse_url((string) home_url('/'), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    return $host;
                }
            }

            return '';
        };
    }

    public function registerUpdateHooks(): void
    {
        if (! function_exists('add_filter') || ! function_exists('add_action')) {
            return;
        }

        add_filter(Constants::WP_FILTER_PRE_SET_SITE_TRANSIENT_UPDATE_PLUGINS, [$this, 'injectUpdateData']);
        add_filter(Constants::WP_FILTER_PLUGINS_API, [$this, 'injectPluginInfo'], 10, 3);
        add_action(Constants::WP_ACTION_REST_API_INIT, [$this, 'registerRestRoute']);
        add_action(Constants::WP_ACTION_DELETE_SITE_TRANSIENT_UPDATE_PLUGINS, [$this, 'clearUpdateCache']);
    }

    public function registerRestRoute(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(
            Constants::UPDATE_REST_NAMESPACE,
            Constants::UPDATE_REST_ROUTE_DOWNLOAD,
            [
                'methods' => class_exists('\\WP_REST_Server') ? \WP_REST_Server::READABLE : 'GET',
                'callback' => [$this, 'handleDownloadProxyRest'],
                'permission_callback' => '__return_true',
                'args' => [
                    Constants::UPDATE_REST_PARAM_SLUG => [
                        'type' => 'string',
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    /**
     * Query SLS for a newer build, memoizing the answer in a transient so
     * a hot WordPress admin does not hammer SLS on every page load.
     *
     * @return UpdateResult|null
     */
    public function checkForUpdates(): ?array
    {
        if ($this->baseUrl === '') {
            return null;
        }

        $resolvedDomain = $this->resolveDomain();
        if ($resolvedDomain === '') {
            return null;
        }

        $cacheKey = $this->transientKey();
        $cached = ($this->getTransient)($cacheKey);
        if (is_array($cached) && array_key_exists(Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE, $cached)) {
            /** @var UpdateResult $cached */
            return $cached;
        }

        $payload = [
            Constants::UPDATE_PAYLOAD_KEY_DOMAIN => $resolvedDomain,
            Constants::UPDATE_PAYLOAD_KEY_SLUG => $this->pluginSlug,
            Constants::UPDATE_PAYLOAD_KEY_CURRENT_VERSION => $this->currentVersion,
        ];
        if ($this->licenseKey !== '') {
            $payload[Constants::UPDATE_PAYLOAD_KEY_LICENSE_KEY] = $this->licenseKey;
        }
        if ($this->includePrerelease) {
            $payload[Constants::UPDATE_PAYLOAD_KEY_IS_PRERELEASE] = true;
        }

        $parsed = ($this->httpPost)($this->baseUrl.Constants::API_ENDPOINT_UPDATES_CHECK, $payload);
        if (! is_array($parsed) || empty($parsed[Constants::UPDATE_RESPONSE_KEY_SUCCESS])) {
            return null;
        }

        $result = $this->normalizeCheckResponse($parsed);

        $ttl = $result[Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE]
            ? Constants::UPDATE_CHECK_CACHE_TTL_SECONDS
            : Constants::UPDATE_CHECK_CACHE_NO_UPDATE_TTL_SECONDS;
        ($this->setTransient)($cacheKey, $result, $ttl);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return UpdateResult
     */
    private function normalizeCheckResponse(array $parsed): array
    {
        $update = $parsed[Constants::UPDATE_RESPONSE_KEY_UPDATE] ?? null;
        $normalizedUpdate = is_array($update) ? $update : null;
        if ($normalizedUpdate !== null) {
            $version = self::normalizeVersion((string) ($normalizedUpdate[Constants::UPDATE_PAYLOAD_KEY_VERSION] ?? ''));
            if ($version === '') {
                $normalizedUpdate = null;
            } else {
                $normalizedUpdate[Constants::UPDATE_PAYLOAD_KEY_VERSION] = $version;
            }
        }

        $latestRaw = isset($parsed[Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION])
            ? (string) $parsed[Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION]
            : ($normalizedUpdate !== null ? (string) $normalizedUpdate[Constants::UPDATE_PAYLOAD_KEY_VERSION] : '');
        $latestVersion = self::normalizeVersion($latestRaw);

        $updateAvailable = $latestVersion !== '' && $this->isRemoteNewer($latestVersion);
        if (! $updateAvailable) {
            $normalizedUpdate = null;
        }

        return [
            Constants::UPDATE_RESPONSE_KEY_UPDATE => $normalizedUpdate,
            Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE => $updateAvailable,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => $latestVersion !== '' ? $latestVersion : null,
        ];
    }

    /**
     * `pre_set_site_transient_update_plugins` filter: advertise the update
     * to WordPress so it appears in Plugins → Available updates.
     *
     * @param  \stdClass|mixed  $transient
     * @return \stdClass|mixed
     */
    public function injectUpdateData(mixed $transient): mixed
    {
        if (! is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $result = $this->checkForUpdates();
        $update = is_array($result) ? ($result[Constants::UPDATE_RESPONSE_KEY_UPDATE] ?? null) : null;
        if (! is_array($update)) {
            return $transient;
        }

        $remoteVersion = self::normalizeVersion((string) ($update[Constants::UPDATE_PAYLOAD_KEY_VERSION] ?? ''));
        if (! $this->isRemoteNewer($remoteVersion)) {
            return $transient;
        }

        $updateObject = (object) [
            'slug' => $this->pluginSlug,
            'plugin' => $this->pluginFile,
            'new_version' => $remoteVersion,
            'package' => $this->downloadProxyUrl(),
            'tested' => (string) ($update[Constants::UPDATE_RESPONSE_KEY_TESTED_WP] ?? ''),
            'requires' => (string) ($update[Constants::UPDATE_RESPONSE_KEY_MIN_WP] ?? ''),
            'sections' => [
                'changelog' => (string) ($update[Constants::UPDATE_RESPONSE_KEY_CHANGELOG] ?? ''),
            ],
        ];

        if (! isset($transient->response) || ! is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[$this->pluginFile] = $updateObject;

        return $transient;
    }

    /**
     * `plugins_api` filter: serve the "View details" modal data.
     *
     * @param  \stdClass|mixed  $result
     * @param  mixed  $action
     * @param  \stdClass|mixed  $args
     * @return \stdClass|mixed
     */
    public function injectPluginInfo(mixed $result, mixed $action, mixed $args): mixed
    {
        if ($action !== Constants::PLUGINS_API_ACTION_INFORMATION) {
            return $result;
        }
        $slug = is_object($args) && isset($args->slug) ? (string) $args->slug : '';
        if ($slug !== $this->pluginSlug) {
            return $result;
        }

        $checkResult = $this->checkForUpdates();
        $update = is_array($checkResult) ? ($checkResult[Constants::UPDATE_RESPONSE_KEY_UPDATE] ?? null) : null;
        if (! is_array($update)) {
            return $result;
        }

        $remoteVersion = self::normalizeVersion((string) ($update[Constants::UPDATE_PAYLOAD_KEY_VERSION] ?? ''));
        if (! $this->isRemoteNewer($remoteVersion)) {
            return $result;
        }

        $info = new \stdClass;
        $info->name = $this->pluginSlug;
        $info->slug = $this->pluginSlug;
        $info->version = $remoteVersion;
        $info->tested = (string) ($update[Constants::UPDATE_RESPONSE_KEY_TESTED_WP] ?? '');
        $info->requires = (string) ($update[Constants::UPDATE_RESPONSE_KEY_MIN_WP] ?? '');
        $info->sections = [
            'changelog' => (string) ($update[Constants::UPDATE_RESPONSE_KEY_CHANGELOG] ?? ''),
        ];
        $info->download_link = $this->downloadProxyUrl();

        return $info;
    }

    /**
     * Resolve the signed download URL for the available update, or null.
     *
     * Keyless free-product path (no license key): build a FRESH, non-expiring
     * tokenless direct-download URL (`?slug=&version=`) at download time from
     * the latest version, rather than reusing the `download_url` cached in the
     * 24h update transient — that cached value can be a 5-minute signed token
     * that has already expired by the time the user clicks "Update", which
     * surfaces as "Download failed. Service Unavailable." The SLS download
     * endpoint serves free/unprotected products by slug+version with no token,
     * so this URL stays valid. Legacy license-gated path (license key present):
     * ask SLS to authorize a download and return the short-lived signed URL.
     */
    public function authorizeDownloadUrl(): ?string
    {
        if ($this->baseUrl === '') {
            return null;
        }

        $result = $this->checkForUpdates();
        $update = is_array($result) ? ($result[Constants::UPDATE_RESPONSE_KEY_UPDATE] ?? null) : null;

        if ($this->licenseKey === '') {
            return $this->keylessDownloadUrl($update);
        }

        if (is_array($update)
            && isset($update[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL])
            && is_string($update[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL])
            && $update[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL] !== '') {
            return $update[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL];
        }

        $payload = [
            Constants::UPDATE_PAYLOAD_KEY_LICENSE_KEY => $this->licenseKey,
            Constants::UPDATE_PAYLOAD_KEY_PRODUCT_SLUG => $this->pluginSlug,
        ];
        $resolvedDomain = $this->resolveDomain();
        if ($resolvedDomain !== '') {
            $payload[Constants::UPDATE_PAYLOAD_KEY_DOMAIN] = $resolvedDomain;
        }
        if (is_array($update) && isset($update[Constants::UPDATE_PAYLOAD_KEY_VERSION]) && is_string($update[Constants::UPDATE_PAYLOAD_KEY_VERSION])) {
            $payload[Constants::UPDATE_PAYLOAD_KEY_VERSION] = $update[Constants::UPDATE_PAYLOAD_KEY_VERSION];
        }
        if ($this->includePrerelease) {
            $payload[Constants::UPDATE_PAYLOAD_KEY_IS_PRERELEASE] = true;
        }

        $parsed = ($this->httpPost)($this->baseUrl.Constants::API_ENDPOINT_DOWNLOADS_AUTHORIZE, $payload);
        if (! is_array($parsed) || empty($parsed[Constants::UPDATE_RESPONSE_KEY_SUCCESS])) {
            return null;
        }

        $url = isset($parsed[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL]) ? (string) $parsed[Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL] : '';

        return $url !== '' ? $url : null;
    }

    /**
     * Build the tokenless keyless direct-download URL for the available
     * update, or null when no resolvable target version is present. The SLS
     * download endpoint accepts `?slug=&version=` with no token for
     * free/unprotected products, so the URL never expires.
     *
     * @param  array<string, mixed>|mixed  $update  The `update` block from the check response.
     */
    private function keylessDownloadUrl(mixed $update): ?string
    {
        $version = '';
        if (is_array($update)
            && isset($update[Constants::UPDATE_PAYLOAD_KEY_VERSION])
            && is_string($update[Constants::UPDATE_PAYLOAD_KEY_VERSION])) {
            $version = $update[Constants::UPDATE_PAYLOAD_KEY_VERSION];
        }

        if ($version === '') {
            return null;
        }

        return $this->baseUrl
            .Constants::API_ENDPOINT_UPDATES_DOWNLOAD
            .'?'.Constants::UPDATE_PAYLOAD_KEY_SLUG.'='.rawurlencode($this->pluginSlug)
            .'&'.Constants::UPDATE_PAYLOAD_KEY_VERSION.'='.rawurlencode($version);
    }

    /**
     * REST download proxy. WordPress fetches this URL to install the update;
     * it 302s to the signed SLS download. Returns a WP_Error only on
     * failure (the success path redirects and exits).
     *
     * @param  object|array<string, mixed>|mixed  $request  WP_REST_Request (loosely typed for tests)
     * @return mixed
     */
    public function handleDownloadProxyRest(mixed $request): mixed
    {
        $slug = '';
        if (is_object($request) && method_exists($request, 'get_param')) {
            $slug = (string) $request->get_param(Constants::UPDATE_REST_PARAM_SLUG);
        } elseif (is_array($request) && isset($request[Constants::UPDATE_REST_PARAM_SLUG])) {
            $slug = (string) $request[Constants::UPDATE_REST_PARAM_SLUG];
        }
        if (function_exists('sanitize_text_field')) {
            $slug = sanitize_text_field($slug);
        }

        if ($slug !== $this->pluginSlug) {
            return $this->restError(
                Constants::REST_ERROR_CODE_SLUG_MISMATCH,
                Constants::REST_ERROR_MESSAGE_SLUG_MISMATCH,
                Constants::HTTP_NOT_FOUND,
            );
        }

        $downloadUrl = $this->authorizeDownloadUrl();
        if ($downloadUrl !== null && function_exists('wp_safe_redirect')) {
            $this->emitNoStoreHeaders();
            $this->allowDownloadHostRedirect($downloadUrl);
            wp_safe_redirect($downloadUrl, Constants::HTTP_FOUND);
            exit;
        }

        return $this->restError(
            Constants::REST_ERROR_CODE_DOWNLOAD_UNAVAILABLE,
            Constants::REST_ERROR_MESSAGE_DOWNLOAD_UNAVAILABLE,
            Constants::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    public function clearUpdateCache(): void
    {
        ($this->deleteTransient)($this->transientKey());
    }

    public function downloadProxyUrl(): string
    {
        $route = '/'.ltrim(Constants::UPDATE_REST_NAMESPACE, '/').'/download/'.rawurlencode($this->pluginSlug);
        if (function_exists('rest_url')) {
            return (string) rest_url($route);
        }
        if (function_exists('home_url')) {
            return rtrim((string) home_url('/'), '/').'/wp-json'.$route;
        }

        return '/wp-json'.$route;
    }

    private function resolveDomain(): string
    {
        return $this->domain !== '' ? $this->domain : ($this->domainResolver)();
    }

    private function transientKey(): string
    {
        $version = (string) preg_replace(
            Constants::UPDATE_TRANSIENT_VERSION_SANITIZE_PATTERN,
            Constants::UPDATE_TRANSIENT_VERSION_SANITIZE_REPLACEMENT,
            $this->currentVersion,
        );

        return $this->transientPrefix
            .$this->pluginSlug
            .Constants::UPDATE_TRANSIENT_VERSION_SEPARATOR
            .$version;
    }

    private function isRemoteNewer(string $remoteVersion): bool
    {
        $remote = self::normalizeVersion($remoteVersion);
        if ($remote === '') {
            return false;
        }
        $current = self::normalizeVersion($this->currentVersion);
        $current = $current !== '' ? $current : trim($this->currentVersion);
        if ($current === '') {
            return false;
        }

        return version_compare($remote, $current, '>');
    }

    private static function normalizeVersion(string $version): string
    {
        $normalized = trim($version);
        if ($normalized === '') {
            return '';
        }
        if (strlen($normalized) > 1 && ($normalized[0] === 'v' || $normalized[0] === 'V') && ctype_digit($normalized[1])) {
            $normalized = substr($normalized, 1);
        }
        if ($normalized === '' || preg_match(Constants::UPDATE_VERSION_PATTERN, $normalized) !== 1) {
            return '';
        }

        return $normalized;
    }

    /**
     * @return callable(string, array<string, mixed>): (array<string, mixed>|null)
     */
    private static function defaultHttpPost(): callable
    {
        return static function (string $url, array $payload): ?array {
            if (! function_exists('wp_remote_post') || ! function_exists('wp_remote_retrieve_body')) {
                return null;
            }
            $body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
            $response = wp_remote_post($url, [
                'timeout' => Constants::DEFAULT_TIMEOUT_SECONDS,
                'headers' => [Constants::HEADER_CONTENT_TYPE => Constants::CONTENT_TYPE_JSON],
                'body' => $body,
            ]);
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return null;
            }
            $decoded = json_decode((string) wp_remote_retrieve_body($response), true);

            return is_array($decoded) ? $decoded : null;
        };
    }

    private function emitNoStoreHeaders(): void
    {
        if (function_exists('headers_sent') && headers_sent()) {
            return;
        }
        if (! function_exists('header')) {
            return;
        }
        header(Constants::HEADER_CACHE_CONTROL.': '.Constants::HEADER_CACHE_CONTROL_NO_STORE, true);
    }

    private function allowDownloadHostRedirect(string $downloadUrl): void
    {
        $host = parse_url($downloadUrl, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || ! function_exists('add_filter')) {
            return;
        }
        add_filter(
            Constants::WORDPRESS_FILTER_ALLOWED_REDIRECT_HOSTS,
            static function (array $hosts) use ($host): array {
                $hosts[] = $host;

                return array_unique($hosts);
            }
        );
    }

    /**
     * @return mixed WP_Error when available, else a plain error array
     */
    private function restError(string $code, string $message, int $status): mixed
    {
        if (class_exists('\\WP_Error')) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }

        return [
            'code' => $code,
            'message' => $message,
            'data' => ['status' => $status],
        ];
    }
}
