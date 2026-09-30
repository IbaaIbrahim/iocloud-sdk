<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * A user inside one of the partner's tenants.
 *
 * `externalId` is the partner's own id for the person: the value its subject
 * tokens carry in the user claim (`sub` by default), unique within the tenant.
 * A federated login finds the user by it, never by email, so a user with none
 * (null) is one no federated login reaches. A user the partner creates starts
 * `pending` and cannot log in until activated.
 */
final readonly class User
{
    public function __construct(
        public string $uuid,
        public string $tenantUuid,
        public string $name,
        public string $email,
        public ?string $externalId,
        public string $status,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $externalId = $payload['external_id'] ?? null;

        return new self(
            uuid: (string) $payload['uuid'],
            tenantUuid: (string) $payload['tenant_uuid'],
            name: (string) $payload['name'],
            email: (string) $payload['email'],
            externalId: $externalId === null ? null : (string) $externalId,
            status: (string) $payload['status'],
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }
}
