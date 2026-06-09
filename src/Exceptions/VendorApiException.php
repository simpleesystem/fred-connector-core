<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Exceptions;

/**
 * Raised on any non-2xx, non-401 response from `/v1/vendor/*`.
 *
 * Carries the cloud's structured error code (e.g. `invalid_dof`,
 * `customer_suspended`, `customer_frozen`, `customer_not_found`) on the
 * `errorCode` property, plus the raw HTTP status and JSON body in `context`.
 *
 * The property is named `errorCode` rather than `code` because PHP 8.4+
 * marks `Exception::$code` readonly and refuses to let a subclass
 * redeclare it as a typed readonly property.
 */
class VendorApiException extends FredCloudException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly ?string $errorCode = null,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }
}
