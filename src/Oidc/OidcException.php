<?php

declare(strict_types=1);

namespace Simplee\FredConnector\Oidc;

use RuntimeException;
use Simplee\FredConnector\Constants;

/**
 * An OAuth2/OIDC protocol error (RFC 6749 §5.2 / §4.1.2.1).
 *
 * Carries the standard `error` code plus an HTTP status so the REST
 * layer can render a spec-compliant `{error, error_description}` body —
 * or, for the authorize endpoint, redirect the error back to the client.
 */
class OidcException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $description,
        public readonly int $httpStatus = Constants::HTTP_BAD_REQUEST,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array<string, string>
     */
    public function toResponseBody(): array
    {
        return [
            'error' => $this->errorCode,
            'error_description' => $this->getMessage(),
        ];
    }
}
