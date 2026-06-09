<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Exceptions;

/**
 * Raised when a vendor API call is attempted before the SDK has a
 * complete configuration (base URL + service token at minimum).
 *
 * The order-lifecycle hooks catch this and log without re-raising so a
 * misconfigured store still completes orders cleanly while the operator
 * fixes the configuration. The admin UI raises a sticky notice so the
 * misconfiguration is surfaced loudly rather than silently logged.
 */
class MissingConfigurationException extends FredCloudException {}
