<?php

namespace IOCloud\Laravel\Exceptions;

class IOCloudAPIException extends IOCloudException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct("{$statusCode} {$errorCode}: {$message}", $statusCode);
    }
}
