<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Update\PluginUpdateChecker;
use WP_Error;
use WP_REST_Request;

final class PluginUpdateCheckerTest extends TestCase
{
    private const SLUG = 'fred-cloud-woocommerce';

    private const PLUGIN_FILE = 'simple-fred-cloud-vendor/simple-fred-cloud-vendor.php';

    private const BASE_URL = 'https://license-admin-data.simpleaisystem.com';

    private const LICENSE = 'abc.def';

    private const DOMAIN = 'talktofred.ai';

    private const CURRENT_VERSION = '1.0.6';

    private const NEWER_VERSION = '1.0.7';

    private const SIGNED_URL = 'https://cdn.simpleaisystem.com/dl/fred-cloud-woocommerce-1.0.7.zip?token=xyz';

    /**
     * @var array<string, mixed>
     */
    private array $store = [];

    /**
     * @var list<array{url: string, payload: array<string, mixed>}>
     */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->calls = [];
    }

    /**
     * @param  callable(string, array<string, mixed>): (array<string, mixed>|null)  $httpPost
     */
    private function checker(
        callable $httpPost,
        string $current = self::CURRENT_VERSION,
        string $license = self::LICENSE,
    ): PluginUpdateChecker {
        return new PluginUpdateChecker(
            pluginSlug: self::SLUG,
            pluginFile: self::PLUGIN_FILE,
            currentVersion: $current,
            baseUrl: self::BASE_URL,
            licenseKey: $license,
            domain: self::DOMAIN,
            includePrerelease: false,
            httpPost: $httpPost,
            getTransient: fn (string $key): mixed => array_key_exists($key, $this->store) ? $this->store[$key] : false,
            setTransient: function (string $key, mixed $value, int $ttl): bool {
                $this->store[$key] = $value;

                return true;
            },
            deleteTransient: function (string $key): bool {
                unset($this->store[$key]);

                return true;
            },
            domainResolver: static fn (): string => self::DOMAIN,
        );
    }

    /**
     * @param  array<string, mixed>  $response
     * @return callable(string, array<string, mixed>): (array<string, mixed>|null)
     */
    private function recordingHttp(array $response): callable
    {
        return function (string $url, array $payload) use ($response): array {
            $this->calls[] = ['url' => $url, 'payload' => $payload];

            return $response;
        };
    }

    public function test_keyless_check_hits_the_network_and_omits_license_key(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
            ],
        ]), license: '');

        $result = $checker->checkForUpdates();

        $this->assertNotNull($result);
        $this->assertTrue($result[Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE]);
        $this->assertCount(1, $this->calls);
        $payload = $this->calls[0]['payload'];
        $this->assertArrayNotHasKey(Constants::UPDATE_PAYLOAD_KEY_LICENSE_KEY, $payload);
        $this->assertSame(self::DOMAIN, $payload[Constants::UPDATE_PAYLOAD_KEY_DOMAIN]);
        $this->assertSame(self::SLUG, $payload[Constants::UPDATE_PAYLOAD_KEY_SLUG]);
        $this->assertSame(self::CURRENT_VERSION, $payload[Constants::UPDATE_PAYLOAD_KEY_CURRENT_VERSION]);
    }

    public function test_keyless_check_skips_the_network_when_domain_is_unresolvable(): void
    {
        $checker = new PluginUpdateChecker(
            pluginSlug: self::SLUG,
            pluginFile: self::PLUGIN_FILE,
            currentVersion: self::CURRENT_VERSION,
            baseUrl: self::BASE_URL,
            licenseKey: '',
            domain: '',
            includePrerelease: false,
            httpPost: $this->recordingHttp([]),
            getTransient: fn (string $key): mixed => array_key_exists($key, $this->store) ? $this->store[$key] : false,
            setTransient: function (string $key, mixed $value, int $ttl): bool {
                $this->store[$key] = $value;

                return true;
            },
            deleteTransient: function (string $key): bool {
                unset($this->store[$key]);

                return true;
            },
            domainResolver: static fn (): string => '',
        );

        $this->assertNull($checker->checkForUpdates());
        $this->assertCount(0, $this->calls);
    }

    public function test_newer_version_is_reported_as_available(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
                Constants::UPDATE_RESPONSE_KEY_CHANGELOG => 'Fix activation fatal.',
            ],
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
        ]));

        $result = $checker->checkForUpdates();

        $this->assertNotNull($result);
        $this->assertTrue($result[Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE]);
        $this->assertSame(self::NEWER_VERSION, $result[Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION]);
    }

    public function test_check_payload_carries_license_domain_slug_and_version(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::CURRENT_VERSION,
        ]));

        $checker->checkForUpdates();

        $this->assertCount(1, $this->calls);
        $payload = $this->calls[0]['payload'];
        $this->assertSame(self::LICENSE, $payload[Constants::UPDATE_PAYLOAD_KEY_LICENSE_KEY]);
        $this->assertSame(self::DOMAIN, $payload[Constants::UPDATE_PAYLOAD_KEY_DOMAIN]);
        $this->assertSame(self::SLUG, $payload[Constants::UPDATE_PAYLOAD_KEY_SLUG]);
        $this->assertSame(self::CURRENT_VERSION, $payload[Constants::UPDATE_PAYLOAD_KEY_CURRENT_VERSION]);
    }

    public function test_same_version_is_not_offered_as_an_update(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::CURRENT_VERSION,
            ],
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::CURRENT_VERSION,
        ]));

        $result = $checker->checkForUpdates();

        $this->assertNotNull($result);
        $this->assertFalse($result[Constants::UPDATE_RESPONSE_KEY_UPDATE_AVAILABLE]);
        $this->assertNull($result[Constants::UPDATE_RESPONSE_KEY_UPDATE]);
    }

    public function test_result_is_memoized_in_a_transient(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION],
        ]));

        $checker->checkForUpdates();
        $checker->checkForUpdates();

        $this->assertCount(1, $this->calls, 'Second check must hit the cache, not the network.');
    }

    public function test_failed_response_is_not_cached(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => false,
        ]));

        $this->assertNull($checker->checkForUpdates());
        $this->assertSame([], $this->store);
    }

    public function test_inject_update_data_advertises_the_update_to_wordpress(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
            ],
        ]));

        $transient = (object) ['checked' => [self::PLUGIN_FILE => self::CURRENT_VERSION], 'response' => []];
        $result = $checker->injectUpdateData($transient);

        $this->assertIsObject($result);
        $this->assertArrayHasKey(self::PLUGIN_FILE, $result->response);
        $entry = $result->response[self::PLUGIN_FILE];
        $this->assertSame(self::NEWER_VERSION, $entry->new_version);
        $this->assertSame(self::PLUGIN_FILE, $entry->plugin);
        $this->assertStringContainsString(self::SLUG, (string) $entry->package);
    }

    public function test_inject_update_data_is_a_noop_before_wordpress_has_checked(): void
    {
        $checker = $this->checker($this->recordingHttp([]));

        $transient = (object) ['checked' => []];
        $result = $checker->injectUpdateData($transient);

        $this->assertSame($transient, $result);
        $this->assertCount(0, $this->calls);
    }

    public function test_inject_plugin_info_returns_details_for_matching_slug(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
                Constants::UPDATE_RESPONSE_KEY_CHANGELOG => 'notes',
            ],
        ]));

        $info = $checker->injectPluginInfo(false, Constants::PLUGINS_API_ACTION_INFORMATION, (object) ['slug' => self::SLUG]);

        $this->assertIsObject($info);
        $this->assertSame(self::SLUG, $info->slug);
        $this->assertSame(self::NEWER_VERSION, $info->version);
    }

    public function test_inject_plugin_info_ignores_other_plugins(): void
    {
        $checker = $this->checker($this->recordingHttp([]));

        $info = $checker->injectPluginInfo(false, Constants::PLUGINS_API_ACTION_INFORMATION, (object) ['slug' => 'some-other-plugin']);

        $this->assertFalse($info);
        $this->assertCount(0, $this->calls);
    }

    public function test_authorize_download_url_returns_the_signed_url(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL => self::SIGNED_URL,
        ]));

        $this->assertSame(self::SIGNED_URL, $checker->authorizeDownloadUrl());
    }

    public function test_keyless_authorize_builds_a_fresh_tokenless_download_url(): void
    {
        // Even when the cached check response carries a (possibly expired)
        // signed token URL, the keyless path must IGNORE it and build a fresh
        // tokenless slug+version URL that never expires.
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
                Constants::UPDATE_RESPONSE_KEY_DOWNLOAD_URL => self::SIGNED_URL,
            ],
        ]), license: '');

        $expected = self::BASE_URL
            .Constants::API_ENDPOINT_UPDATES_DOWNLOAD
            .'?'.Constants::UPDATE_PAYLOAD_KEY_SLUG.'='.rawurlencode(self::SLUG)
            .'&'.Constants::UPDATE_PAYLOAD_KEY_VERSION.'='.rawurlencode(self::NEWER_VERSION);

        $url = $checker->authorizeDownloadUrl();

        $this->assertSame($expected, $url);
        $this->assertStringNotContainsString('token=', (string) $url);
        $this->assertCount(1, $this->calls, 'Keyless download must not make a second /downloads/authorize call.');
        $this->assertSame(
            self::BASE_URL.Constants::API_ENDPOINT_UPDATES_CHECK,
            $this->calls[0]['url'],
        );
    }

    public function test_keyless_authorize_returns_null_when_no_update_version_is_available(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::CURRENT_VERSION,
        ]), license: '');

        $this->assertNull($checker->authorizeDownloadUrl());
    }

    public function test_update_transient_key_changes_with_the_installed_version(): void
    {
        $response = [
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION],
        ];

        $this->checker($this->recordingHttp($response), current: self::CURRENT_VERSION, license: '')->checkForUpdates();
        $keysAfterOldVersion = array_keys($this->store);

        $this->store = [];
        $this->checker($this->recordingHttp($response), current: self::NEWER_VERSION, license: '')->checkForUpdates();
        $keysAfterNewVersion = array_keys($this->store);

        $this->assertNotSame(
            $keysAfterOldVersion,
            $keysAfterNewVersion,
            'A version change must yield a different update-check transient key so stale results cannot persist.',
        );
    }

    public function test_download_proxy_rejects_a_mismatched_slug(): void
    {
        $checker = $this->checker($this->recordingHttp([]));

        $request = new WP_REST_Request;
        $request->set_param(Constants::UPDATE_REST_PARAM_SLUG, 'not-this-plugin');

        $error = $checker->handleDownloadProxyRest($request);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame(Constants::REST_ERROR_CODE_SLUG_MISMATCH, $error->get_error_code());
    }

    public function test_clear_update_cache_removes_the_transient(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION],
        ]));

        $checker->checkForUpdates();
        $this->assertNotSame([], $this->store);

        $checker->clearUpdateCache();
        $this->assertSame([], $this->store);
    }
}
