<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Resources\QosBundle;

class QosBundleTest extends TestCase
{
    public function test_empty_returns_a_bundle_with_no_values(): void
    {
        $bundle = QosBundle::empty();

        $this->assertSame([], $bundle->toArray());
        $this->assertFalse($bundle->has(Constants::DOF_CHAT_RPM));
    }

    public function test_with_layers_new_values_above_existing_ones(): void
    {
        $base = new QosBundle([Constants::DOF_CHAT_RPM => 5, Constants::DOF_RAG_QUERY_RPM => 10]);

        $layered = $base->with([Constants::DOF_CHAT_RPM => 60]);

        $this->assertSame(60, $layered->int(Constants::DOF_CHAT_RPM));
        $this->assertSame(10, $layered->int(Constants::DOF_RAG_QUERY_RPM));
    }

    public function test_int_falls_back_to_default_when_value_is_missing_or_non_numeric(): void
    {
        $bundle = new QosBundle([Constants::DOF_CHAT_RPM => 'not-a-number']);

        $this->assertSame(42, $bundle->int(Constants::DOF_CHAT_RPM, 42));
        $this->assertSame(7, $bundle->int(Constants::DOF_RAG_QUERY_RPM, 7));
    }

    public function test_bool_coerces_string_and_int_inputs(): void
    {
        $bundle = new QosBundle([
            'enabled_string' => 'yes',
            'disabled_string' => 'no',
            'enabled_int' => 1,
            'disabled_int' => 0,
        ]);

        $this->assertTrue($bundle->bool('enabled_string'));
        $this->assertFalse($bundle->bool('disabled_string'));
        $this->assertTrue($bundle->bool('enabled_int'));
        $this->assertFalse($bundle->bool('disabled_int'));
        $this->assertTrue($bundle->bool('missing_key', true));
    }

    public function test_string_list_filters_to_known_provider_slugs_and_dedupes(): void
    {
        $bundle = new QosBundle([
            Constants::DOF_LLM_PROVIDERS => [
                Constants::PROVIDER_OPENAI,
                Constants::PROVIDER_OPENAI,
                'unknown-provider',
                '',
                Constants::PROVIDER_CLAUDE,
            ],
        ]);

        $this->assertSame(
            [Constants::PROVIDER_OPENAI, Constants::PROVIDER_CLAUDE],
            $bundle->stringList(Constants::DOF_LLM_PROVIDERS),
        );
    }

    public function test_is_unlimited_recognizes_the_unlimited_sentinel(): void
    {
        $unlimited = new QosBundle([Constants::DOF_CHAT_RPM => Constants::QOS_UNLIMITED]);
        $bounded = new QosBundle([Constants::DOF_CHAT_RPM => 60]);

        $this->assertTrue($unlimited->isUnlimited(Constants::DOF_CHAT_RPM));
        $this->assertFalse($bounded->isUnlimited(Constants::DOF_CHAT_RPM));
    }
}
