<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Factories;

use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Tests\TestingConstants;

/**
 * Builds a `customer_snapshot` payload matching the cloud's
 * `/v1/vendor/customers/*` response shape. Used everywhere a unit test
 * needs a realistic upsert / show / suspend / resume body without
 * spelling out every key inline.
 */
final class CustomerResponseFactory
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function build(array $overrides = []): array
    {
        return $overrides + [
            'customer_id' => TestingConstants::CUSTOMER_ID,
            'display_name' => 'Buyer Name',
            'suspended_at' => null,
            'frozen_at' => null,
            'rotated_at' => '2026-05-08T12:00:00Z',
            'dof' => [
                Constants::DOF_CHAT_RPM => 30,
                Constants::DOF_REALTIME_VOICE_ENABLED => true,
                Constants::DOF_LLM_PROVIDERS => [Constants::PROVIDER_OPENAI],
            ],
        ];
    }
}
