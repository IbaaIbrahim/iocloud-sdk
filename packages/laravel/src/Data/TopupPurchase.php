<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * One tenant's purchase of a top-up package.
 *
 * `credits` is snapshotted at purchase time, so editing the package later
 * never changes what an existing purchase granted. `validTo` is null when the
 * credits never expire.
 */
final readonly class TopupPurchase
{
    public function __construct(
        public string $uuid,
        public string $tenantUuid,
        public string $tenantName,
        public ?string $packageUuid,
        public ?string $packageName,
        public int $credits,
        public string $status,
        public ?DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validTo,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $packageUuid = $payload['package_uuid'] ?? null;
        $packageName = $payload['package_name'] ?? null;
        $validFrom = $payload['valid_from'] ?? null;
        $validTo = $payload['valid_to'] ?? null;

        return new self(
            uuid: (string) $payload['uuid'],
            tenantUuid: (string) $payload['tenant_uuid'],
            tenantName: (string) $payload['tenant_name'],
            packageUuid: $packageUuid === null ? null : (string) $packageUuid,
            packageName: $packageName === null ? null : (string) $packageName,
            credits: (int) $payload['credits'],
            status: (string) $payload['status'],
            validFrom: $validFrom === null ? null : new DateTimeImmutable((string) $validFrom),
            validTo: $validTo === null ? null : new DateTimeImmutable((string) $validTo),
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }
}
