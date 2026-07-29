<?php

namespace IOCloud\Laravel\Exceptions;

/**
 * The token exchange endpoint rejected a partner-signed subject token.
 *
 * Carries the RFC 6749 error body. `$error` is the machine-readable reason
 * (`invalid_grant`, `invalid_target`, …); the platform deliberately keeps
 * `$errorDescription` generic — its audit log holds the precise cause.
 */
class IOCloudTokenExchangeException extends IOCloudAPIException
{
    public function __construct(
        int $statusCode,
        public readonly string $error,
        public readonly string $errorDescription,
    ) {
        parent::__construct(
            statusCode: $statusCode,
            errorCode: $error,
            message: $errorDescription,
        );
    }
}
