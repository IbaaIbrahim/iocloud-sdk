<?php

namespace IOCloud\Laravel\Data;

final readonly class TenantCredential
{
    public function __construct(
        public string $credentialUuid,
        public string $tenantUuid,
        public string $clientId,
        public string $clientSecret,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            credentialUuid: (string) $payload['credential_uuid'],
            tenantUuid: (string) $payload['tenant_uuid'],
            clientId: (string) $payload['client_id'],
            clientSecret: (string) $payload['client_secret'],
        );
    }
}
