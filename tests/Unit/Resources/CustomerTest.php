<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests\Unit\Resources;

use PHPUnit\Framework\TestCase;
use Simplee\FredConnector\Constants;
use Simplee\FredConnector\Resources\Customer;
use Simplee\FredConnector\Tests\Factories\CustomerResponseFactory;
use Simplee\FredConnector\Tests\TestingConstants;

class CustomerTest extends TestCase
{
    public function test_from_array_maps_every_canonical_response_field(): void
    {
        $customer = Customer::fromArray(CustomerResponseFactory::build());

        $this->assertSame(TestingConstants::CUSTOMER_ID, $customer->customerId);
        $this->assertSame('Buyer Name', $customer->displayName);
        $this->assertSame(30, $customer->dof->int(Constants::DOF_CHAT_RPM));
        $this->assertFalse($customer->isSuspended());
        $this->assertFalse($customer->isFrozen());
    }

    public function test_is_suspended_reflects_the_suspended_at_timestamp(): void
    {
        $customer = Customer::fromArray(CustomerResponseFactory::build([
            'suspended_at' => '2026-05-08T12:00:00Z',
        ]));

        $this->assertTrue($customer->isSuspended());
    }

    public function test_is_frozen_reflects_the_frozen_at_timestamp(): void
    {
        $customer = Customer::fromArray(CustomerResponseFactory::build([
            'frozen_at' => '2026-05-08T12:00:00Z',
        ]));

        $this->assertTrue($customer->isFrozen());
    }

    public function test_from_array_tolerates_a_missing_dof_payload(): void
    {
        $customer = Customer::fromArray(['customer_id' => TestingConstants::CUSTOMER_ID]);

        $this->assertSame(TestingConstants::CUSTOMER_ID, $customer->customerId);
        $this->assertSame([], $customer->dof->toArray());
    }
}
