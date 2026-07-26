<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

final readonly class Tenant
{
    public function __construct(
        public string $uuid,
        public string $applicationUuid,
        public string $name,
        public string $slug,
        public string $contactEmail,
        public string $status,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            uuid: (string) $payload['uuid'],
            applicationUuid: (string) $payload['application_uuid'],
            name: (string) $payload['name'],
            slug: (string) $payload['slug'],
            contactEmail: (string) $payload['contact_email'],
            status: (string) $payload['status'],
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }
}
