<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Config\BaseUrlResolver;

/**
 * Reconciled from the WooCommerce vendor connector's and the login
 * connector's (previously hand-copied) BaseUrlResolver suites; the
 * option key and default URL are now injected per connector.
 */
final class BaseUrlResolverTest extends TestCase
{
    private const OPTION_KEY = 'fred_connector_test_base_url';

    private const DEFAULT_BASE_URL = 'https://api.talktofred.ai';

    protected function setUp(): void
    {
        $GLOBALS['__wp_options_fixture'] = [];
    }

    private function makeResolver(): BaseUrlResolver
    {
        return new BaseUrlResolver(self::OPTION_KEY, self::DEFAULT_BASE_URL);
    }

    public function test_falls_back_to_injected_default_when_option_row_is_absent(): void
    {
        // Regression contract (woo v1.1.7 / login v1.0.9): WordPress skips
        // persisting values equal to a registered default, so a token-only
        // install has no base-URL row — the resolver must still hand back
        // the production default, never ''.
        $this->assertSame(
            rtrim(self::DEFAULT_BASE_URL, '/'),
            $this->makeResolver()->resolve(),
        );
    }

    public function test_falls_back_to_injected_default_when_saved_value_is_blank(): void
    {
        $GLOBALS['__wp_options_fixture'][self::OPTION_KEY] = '   ';

        $this->assertSame(
            rtrim(self::DEFAULT_BASE_URL, '/'),
            $this->makeResolver()->resolve(),
        );
    }

    public function test_returns_saved_override_with_trailing_slash_trimmed(): void
    {
        $GLOBALS['__wp_options_fixture'][self::OPTION_KEY] = 'https://cloud.customer.test/';

        $this->assertSame('https://cloud.customer.test', $this->makeResolver()->resolve());
    }

    public function test_reads_through_the_injected_option_reader(): void
    {
        $resolver = new BaseUrlResolver(
            self::OPTION_KEY,
            self::DEFAULT_BASE_URL,
            static fn (string $key, mixed $default): mixed => 'https://injected.customer.test/',
        );

        $this->assertSame('https://injected.customer.test', $resolver->resolve());
    }
}
