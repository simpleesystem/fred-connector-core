<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Exceptions;

/**
 * Raised on 401 from `/v1/vendor/*`.
 *
 * Always means the service token in this SDK does not match the cloud's
 * `FRED_CLOUD_VENDOR_SERVICE_TOKEN`. The admin notice flow tells the
 * operator to re-pair via `bin/keys`.
 */
class VendorAuthenticationException extends FredCloudException {}
