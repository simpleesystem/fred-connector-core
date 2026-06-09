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

    private const PROXY_PACKAGE_URL = 'https://talktofred.ai/wp-json/fred-cloud-updates/v1/download/fred-cloud-woocommerce';

    private const PROXY_PACKAGE_URL_SUBDIR = 'http://www.other.example/blog/wp-json/fred-cloud-updates/v1/download/fred-cloud-woocommerce/';

    private const PROXY_PACKAGE_URL_PLAIN_PERMALINKS = 'https://talktofred.ai/?rest_route=/fred-cloud-updates/v1/download/fred-cloud-woocommerce';

    private const FOREIGN_PACKAGE_URL = 'https://downloads.wordpress.org/plugin/some-other-plugin.1.2.3.zip';

    private const PREFIX_COLLISION_PACKAGE_URL = 'https://talktofred.ai/wp-json/fred-cloud-updates/v1/download/fred-cloud-woocommerce-pro';

    private const LOCAL_ZIP_PATH = '/tmp/fred-cloud-woocommerce-update.zip';

    private const DOWNLOAD_ERROR_DETAIL = 'cURL error 28: connection timed out';

    private const REPLY_ALREADY_HANDLED = '/tmp/already-downloaded.zip';

    /**
     * @var array<string, mixed>
     */
    private array $store = [];

    /**
     * @var list<array{url: string, payload: array<string, mixed>}>
     */
    private array $calls = [];

    /**
     * @var list<string>
     */
    private array $downloadedUrls = [];

    /**
     * @var list<string>
     */
    private array $logLines = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->calls = [];
        $this->downloadedUrls = [];
        $this->logLines = [];
    }

    /**
     * @param  callable(string, array<string, mixed>): (array<string, mixed>|null)  $httpPost
     * @param  (callable(string): mixed)|null  $fileDownloader
     */
    private function checker(
        callable $httpPost,
        string $current = self::CURRENT_VERSION,
        string $license = self::LICENSE,
        ?callable $fileDownloader = null,
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
            fileDownloader: $fileDownloader ?? function (string $url): mixed {
                $this->downloadedUrls[] = $url;

                return self::LOCAL_ZIP_PATH;
            },
            logger: function (string $message): void {
                $this->logLines[] = $message;
            },
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function keylessUpdateAvailableResponse(): array
    {
        return [
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::NEWER_VERSION,
            Constants::UPDATE_RESPONSE_KEY_UPDATE => [
                Constants::UPDATE_PAYLOAD_KEY_VERSION => self::NEWER_VERSION,
            ],
        ];
    }

    private function expectedKeylessDownloadUrl(): string
    {
        return self::BASE_URL
            .Constants::API_ENDPOINT_UPDATES_DOWNLOAD
            .'?'.Constants::UPDATE_PAYLOAD_KEY_SLUG.'='.rawurlencode(self::SLUG)
            .'&'.Constants::UPDATE_PAYLOAD_KEY_VERSION.'='.rawurlencode(self::NEWER_VERSION);
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

    /**
     * @return callable(string, array<string, mixed>): (array<string, mixed>|null)
     */
    private function failingHttp(): callable
    {
        return function (string $url, array $payload): ?array {
            $this->calls[] = ['url' => $url, 'payload' => $payload];

            return null;
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

    public function test_intercept_resolves_own_proxy_package_in_process_and_returns_the_local_file(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL);

        $this->assertSame(self::LOCAL_ZIP_PATH, $result);
        $this->assertSame([$this->expectedKeylessDownloadUrl()], $this->downloadedUrls);
    }

    public function test_intercept_matches_proxy_url_on_subdirectory_installs_with_home_url_variations(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL_SUBDIR);

        $this->assertSame(self::LOCAL_ZIP_PATH, $result);
    }

    public function test_intercept_matches_proxy_url_in_plain_permalink_rest_route_form(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL_PLAIN_PERMALINKS);

        $this->assertSame(self::LOCAL_ZIP_PATH, $result);
    }

    public function test_intercept_ignores_packages_of_other_plugins(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(false, self::FOREIGN_PACKAGE_URL);

        $this->assertFalse($result);
        $this->assertSame([], $this->downloadedUrls);
        $this->assertCount(0, $this->calls, 'Foreign packages must not trigger any SLS traffic.');
    }

    public function test_intercept_ignores_slugs_that_merely_share_a_prefix(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(false, self::PREFIX_COLLISION_PACKAGE_URL);

        $this->assertFalse($result);
        $this->assertSame([], $this->downloadedUrls);
    }

    public function test_intercept_leaves_an_already_handled_reply_untouched(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $result = $checker->interceptPackageDownload(self::REPLY_ALREADY_HANDLED, self::PROXY_PACKAGE_URL);

        $this->assertSame(self::REPLY_ALREADY_HANDLED, $result);
        $this->assertSame([], $this->downloadedUrls);
    }

    public function test_intercept_returns_a_descriptive_error_when_no_download_can_be_resolved(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::CURRENT_VERSION,
        ]), license: '');

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(Constants::UPDATE_ERROR_CODE_PACKAGE_RESOLVE_FAILED, $result->get_error_code());
        $this->assertStringContainsString(Constants::UPDATE_FAILURE_STAGE_NO_UPDATE_VERSION, $result->get_error_message());
        $this->assertNotSame([], $this->logLines, 'A resolve failure must be logged.');
    }

    public function test_intercept_reports_the_check_failed_stage_when_sls_is_unreachable(): void
    {
        $checker = $this->checker($this->failingHttp(), license: '');

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringContainsString(Constants::UPDATE_FAILURE_STAGE_CHECK_FAILED, $result->get_error_message());
    }

    public function test_intercept_wraps_file_download_failures_in_a_descriptive_error(): void
    {
        $checker = $this->checker(
            $this->recordingHttp($this->keylessUpdateAvailableResponse()),
            license: '',
            fileDownloader: static fn (string $url): mixed => new WP_Error(
                Constants::UPDATE_ERROR_CODE_PACKAGE_FETCH_FAILED,
                self::DOWNLOAD_ERROR_DETAIL,
            ),
        );

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(Constants::UPDATE_ERROR_CODE_PACKAGE_FETCH_FAILED, $result->get_error_code());
        $this->assertStringContainsString(self::DOWNLOAD_ERROR_DETAIL, $result->get_error_message());
        $this->assertNotSame([], $this->logLines, 'A fetch failure must be logged.');
    }

    public function test_intercept_falls_back_to_the_default_download_path_without_a_downloader(): void
    {
        $checker = $this->checker(
            $this->recordingHttp($this->keylessUpdateAvailableResponse()),
            license: '',
            fileDownloader: static fn (string $url): mixed => null,
        );

        $result = $checker->interceptPackageDownload(false, self::PROXY_PACKAGE_URL);

        $this->assertFalse($result);
    }

    public function test_authorize_failure_stage_is_null_after_a_successful_keyless_resolve(): void
    {
        $checker = $this->checker($this->recordingHttp($this->keylessUpdateAvailableResponse()), license: '');

        $checker->authorizeDownloadUrl();

        $this->assertNull($checker->lastAuthorizeFailureStage());
    }

    public function test_licensed_authorize_failure_reports_the_authorize_stage(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => false,
        ]));

        $this->assertNull($checker->authorizeDownloadUrl());
        $this->assertSame(Constants::UPDATE_FAILURE_STAGE_AUTHORIZE_FAILED, $checker->lastAuthorizeFailureStage());
    }

    public function test_download_proxy_unavailable_path_logs_the_failing_stage(): void
    {
        $checker = $this->checker($this->recordingHttp([
            Constants::UPDATE_RESPONSE_KEY_SUCCESS => true,
            Constants::UPDATE_RESPONSE_KEY_LATEST_VERSION => self::CURRENT_VERSION,
        ]), license: '');

        $request = new WP_REST_Request;
        $request->set_param(Constants::UPDATE_REST_PARAM_SLUG, self::SLUG);

        $error = $checker->handleDownloadProxyRest($request);

        $this->assertInstanceOf(WP_Error::class, $error);
        $this->assertSame(Constants::REST_ERROR_CODE_DOWNLOAD_UNAVAILABLE, $error->get_error_code());
        $this->assertStringContainsString(Constants::UPDATE_FAILURE_STAGE_NO_UPDATE_VERSION, $error->get_error_message());
        $this->assertCount(1, $this->logLines);
        $this->assertStringContainsString(Constants::UPDATE_FAILURE_STAGE_NO_UPDATE_VERSION, $this->logLines[0]);
    }

    public function test_register_update_hooks_wires_the_pre_download_interceptor(): void
    {
        $checker = $this->checker($this->recordingHttp([]));

        $checker->registerUpdateHooks();

        $records = $GLOBALS['__wp_filters_fixture'] ?? [];
        $this->assertIsArray($records);
        $hooks = [];
        foreach ($records as $record) {
            if (is_array($record) && isset($record['hook'])) {
                $hooks[] = (string) $record['hook'];
            }
        }
        $this->assertContains(Constants::WP_FILTER_UPGRADER_PRE_DOWNLOAD, $hooks);
    }
}
