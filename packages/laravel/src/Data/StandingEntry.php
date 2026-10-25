<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * One instruction or memory the assistant is told in every conversation.
 *
 * `scope` is `'tenant'` for a workspace entry, which every user of the tenant
 * gets, and `'user'` for one user's personal entry. `kind` is `'instruction'`
 * (a directive) or `'memory'` (a fact).
 *
 * `expiresOn` is the last day the entry applies, inclusive, in UTC, as
 * `'Y-m-d'`, and null for one that never expires: a calendar day, not an
 * instant, so it stays a string. `expired` says that day has passed: an expired
 * entry is still listed, but no longer told. `status` is `'active'` or
 * `'retired'`; a retired entry is kept but never told, and listed only on
 * request. So an entry applies when {@see isActive()} and not `expired`.
 *
 * `source` is `'api'` for an entry someone wrote through the API and `'agent'`
 * for one the assistant saved itself, in the conversation `sourceJobUuid`
 * names.
 */
final readonly class StandingEntry
{
    public function __construct(
        public string $uuid,
        public string $scope,
        public string $kind,
        public string $body,
        public string $status,
        public ?string $expiresOn,
        public bool $expired,
        public string $source,
        public ?string $sourceJobUuid,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $expiresOn = $payload['expires_on'] ?? null;
        $sourceJobUuid = $payload['source_job_uuid'] ?? null;

        return new self(
            uuid: (string) $payload['uuid'],
            scope: (string) $payload['scope'],
            kind: (string) $payload['kind'],
            body: (string) $payload['body'],
            status: (string) $payload['status'],
            expiresOn: $expiresOn === null ? null : (string) $expiresOn,
            expired: (bool) ($payload['expired'] ?? false),
            source: (string) $payload['source'],
            sourceJobUuid: $sourceJobUuid === null ? null : (string) $sourceJobUuid,
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
            updatedAt: new DateTimeImmutable((string) $payload['updated_at']),
        );
    }
}
