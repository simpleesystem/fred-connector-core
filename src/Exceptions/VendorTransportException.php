<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Exceptions;

/**
 * Raised on transport-layer failures (DNS, TLS, connect timeout, read
 * timeout) — i.e. anything that prevents the SDK from getting a real
 * HTTP response from the cloud.
 *
 * The order-lifecycle hooks treat this as a transient and queue a
 * retry; persistent transport errors raise a sticky admin notice.
 */
class VendorTransportException extends FredCloudException {}
