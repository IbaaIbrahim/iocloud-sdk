<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

final readonly class ExternalTenantMapping
{
    public function __construct(
        public string $identityProviderUuid,
        public string $tenantUuid,
        public string $externalTenantId,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            identityProviderUuid: (string) $payload['identity_provider_uuid'],
            tenantUuid: (string) $payload['tenant_uuid'],
            externalTenantId: (string) $payload['external_tenant_id'],
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }
}
