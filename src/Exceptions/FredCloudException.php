<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for all Fred Cloud Vendor SDK errors.
 *
 * Mirrors the SLS Vendor SDK's exception shape: every failure that
 * crosses the SDK/WP boundary is one of the typed subclasses, never a
 * raw `RuntimeException` or `GuzzleException`. The admin UI and the
 * order-lifecycle code branch on `instanceof` rather than string-matching
 * messages.
 */
class FredCloudException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
