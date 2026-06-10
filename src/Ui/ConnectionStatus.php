<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Ui;

use Simplee\FredConnector\Constants;

/**
 * Resolves the Fred Cloud connection status and renders the matching
 * admin notice for the connector's settings page.
 *
 * Three states:
 *   - NOT_CONFIGURED — base URL or service token is missing (cheap,
 *     no network call).
 *   - CONNECTED      — both are set and a live probe succeeded.
 *   - UNREACHABLE    — both are set but the live probe failed (bad
 *     token, wrong base URL, cloud down, network blocked).
 *
 * NOT_CONFIGURED and UNREACHABLE both render the "not connected yet"
 * notice with an "open settings" link so the admin always has a clear
 * next step; CONNECTED renders a success notice. The notice copy is
 * connector-specific (each connector's own product name) and injected
 * via the constructor, so two connectors active on the same site render
 * distinct notices.
 */
final class ConnectionStatus
{
    public const CONNECTED = 'connected';

    public const NOT_CONFIGURED = 'not_configured';

    public const UNREACHABLE = 'unreachable';

    /**
     * @param  string  $connectedNotice  copy for the success notice (e.g. "<Product> is connected.")
     * @param  string  $notConnectedNotice  copy for the not-connected notice (e.g. "<Product> is not connected yet.")
     * @param  string  $settingsLinkText  link text appended to the not-connected notice
     */
    public function __construct(
        private readonly string $connectedNotice,
        private readonly string $notConnectedNotice,
        private readonly string $settingsLinkText,
    ) {}

    /**
     * @param  callable(): bool  $probe
     */
    public static function evaluate(string $baseUrl, string $token, callable $probe): string
    {
        if (trim($baseUrl) === '' || trim($token) === '') {
            return self::NOT_CONFIGURED;
        }

        return $probe() ? self::CONNECTED : self::UNREACHABLE;
    }

    public function noticeHtml(string $status, string $settingsUrl): string
    {
        if ($status === self::CONNECTED) {
            return Constants::ADMIN_NOTICE_HTML_OPEN
                .Constants::ADMIN_NOTICE_CLASS_SUCCESS
                .Constants::ADMIN_NOTICE_HTML_MIDDLE
                .esc_html($this->connectedNotice)
                .Constants::ADMIN_NOTICE_HTML_CLOSE;
        }

        $link = ' <a href="'.esc_url($settingsUrl).'">'
            .esc_html($this->settingsLinkText).'</a>';

        return Constants::ADMIN_NOTICE_HTML_OPEN
            .Constants::ADMIN_NOTICE_CLASS_ERROR
            .Constants::ADMIN_NOTICE_HTML_MIDDLE
            .esc_html($this->notConnectedNotice)
            .$link
            .Constants::ADMIN_NOTICE_HTML_CLOSE;
    }
}
