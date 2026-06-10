<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Ui\ConnectionStatus;

/**
 * Reconciled from the WooCommerce vendor connector's and the login
 * connector's (previously hand-copied) ConnectionStatus suites. The
 * notice copy is injected per connector, so the suite covers both
 * parameterizations and pins that two connectors active on the same
 * site render distinct product names.
 */
final class ConnectionStatusTest extends TestCase
{
    private const SETTINGS_URL = 'https://example.test/wp-admin/options-general.php';

    private const TOKEN = 'fred_vt_connection_status_fixture';

    private const BASE_URL = 'https://api.talktofred.ai';

    private const WOO_CONNECTED = 'Talk to Fred Cloud Vendor is connected.';

    private const WOO_NOT_CONNECTED = 'Talk to Fred Cloud Vendor is not connected yet.';

    private const LOGIN_CONNECTED = 'Talk to Fred — Sign-In is connected.';

    private const LOGIN_NOT_CONNECTED = 'Talk to Fred — Sign-In is not connected yet.';

    private const SETTINGS_LINK_TEXT = 'Open settings to finish setup';

    private function makeWooStatus(): ConnectionStatus
    {
        return new ConnectionStatus(self::WOO_CONNECTED, self::WOO_NOT_CONNECTED, self::SETTINGS_LINK_TEXT);
    }

    private function makeLoginStatus(): ConnectionStatus
    {
        return new ConnectionStatus(self::LOGIN_CONNECTED, self::LOGIN_NOT_CONNECTED, self::SETTINGS_LINK_TEXT);
    }

    public function test_evaluate_is_not_configured_when_base_url_is_missing(): void
    {
        $status = ConnectionStatus::evaluate('', self::TOKEN, fn (): bool => true);

        $this->assertSame(ConnectionStatus::NOT_CONFIGURED, $status);
    }

    public function test_evaluate_is_not_configured_when_token_is_missing(): void
    {
        $status = ConnectionStatus::evaluate(self::BASE_URL, '   ', fn (): bool => true);

        $this->assertSame(ConnectionStatus::NOT_CONFIGURED, $status);
    }

    public function test_evaluate_is_connected_when_probe_succeeds(): void
    {
        $status = ConnectionStatus::evaluate(self::BASE_URL, self::TOKEN, fn (): bool => true);

        $this->assertSame(ConnectionStatus::CONNECTED, $status);
    }

    public function test_evaluate_is_unreachable_when_probe_fails(): void
    {
        $status = ConnectionStatus::evaluate(self::BASE_URL, self::TOKEN, fn (): bool => false);

        $this->assertSame(ConnectionStatus::UNREACHABLE, $status);
    }

    public function test_connected_notice_renders_the_injected_connected_copy(): void
    {
        $html = $this->makeWooStatus()->noticeHtml(ConnectionStatus::CONNECTED, self::SETTINGS_URL);

        $this->assertStringContainsString(self::WOO_CONNECTED, $html);
        $this->assertStringContainsString(Constants::ADMIN_NOTICE_CLASS_SUCCESS, $html);
    }

    public function test_unreachable_notice_renders_the_injected_not_connected_copy_with_settings_link(): void
    {
        $html = $this->makeWooStatus()->noticeHtml(ConnectionStatus::UNREACHABLE, self::SETTINGS_URL);

        $this->assertStringContainsString(self::WOO_NOT_CONNECTED, $html);
        $this->assertStringContainsString(self::SETTINGS_LINK_TEXT, $html);
        $this->assertStringContainsString(self::SETTINGS_URL, $html);
        $this->assertStringContainsString(Constants::ADMIN_NOTICE_CLASS_ERROR, $html);
    }

    public function test_not_configured_notice_renders_the_injected_not_connected_copy(): void
    {
        $html = $this->makeWooStatus()->noticeHtml(ConnectionStatus::NOT_CONFIGURED, self::SETTINGS_URL);

        $this->assertStringContainsString(self::WOO_NOT_CONNECTED, $html);
    }

    public function test_two_parameterizations_render_distinct_product_copy(): void
    {
        // Regression (login v1.0.2): the login connector's copy was made
        // verbatim-identical to the WooCommerce vendor connector's, so an
        // admin with both plugins active saw two identical notices. With
        // injected copy, each connector renders its own product name.
        $wooHtml = $this->makeWooStatus()->noticeHtml(ConnectionStatus::UNREACHABLE, self::SETTINGS_URL);
        $loginHtml = $this->makeLoginStatus()->noticeHtml(ConnectionStatus::UNREACHABLE, self::SETTINGS_URL);

        $this->assertStringContainsString(self::WOO_NOT_CONNECTED, $wooHtml);
        $this->assertStringContainsString(self::LOGIN_NOT_CONNECTED, $loginHtml);
        $this->assertNotSame($wooHtml, $loginHtml);
        $this->assertStringNotContainsString(self::WOO_NOT_CONNECTED, $loginHtml);
    }
}
