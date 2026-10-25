<?php

namespace IOCloud\Laravel\Exceptions;

/**
 * An API response reported a failure.
 *
 * `$errorCode` is the response's machine-readable `code` (`TENANT_NOT_FOUND`,
 * `STANDING_CONTEXT_FULL`, …). `$reason` is its `error.reason` when the platform
 * gives one — the finer cause of a refusal, such as `STANDING_ENTRY_TOO_LONG`
 * under `STANDING_ENTRY_INVALID` — and null otherwise. `$fields` is a
 * `422 VALIDATION_ERROR`'s `error.fields`, each invalid field's path mapped to
 * its messages, and empty otherwise.
 */
class IOCloudAPIException extends IOCloudException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorCode,
        string $message,
        public readonly ?string $reason = null,
        public readonly array $fields = [],
    ) {
        parent::__construct("{$statusCode} {$errorCode}: {$message}", $statusCode);
    }
}
