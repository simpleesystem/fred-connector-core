<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Tests;

/**
 * Constants used across the test suite so tests don't repeat string
 * literals.
 */
final class TestingConstants
{
    public const BASE_URL = 'https://api.talktofred.test';

    public const VENDOR_SERVICE_TOKEN = 'vendor-service-token-fixture';

    public const JWT_SIGNING_SECRET = 'jwt-signing-secret-fixture';

    public const CUSTOMER_ID = 'wc:fa317f3da76a73e75d3a49b2cd2f47c8a4b3fa3a4b04b41a8a23b8ef3e6c4d51';

    public const EMAIL = 'buyer@example.test';

    public const ORDER_ID = 12345;

    public const ORDER_ID_NUMERIC_STRING = '12345';

    public const ORDER_ID_NON_NUMERIC = 'not-an-order';

    public const TIER_PRO = 'pro';

    public const TIER_ENTERPRISE = 'enterprise';

    private function __construct() {}
}
