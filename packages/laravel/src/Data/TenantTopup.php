<?php

namespace IOCloud\Laravel\Data;

/**
 * A tenant's top-up plus whatever its activation provisioned.
 *
 * `provisioned` is null when this call provisioned nothing — the purchase is
 * still pending, or an already-active one was activated again (activation is
 * idempotent).
 */
final readonly class TenantTopup
{
    public function __construct(
        public TopupPurchase $purchase,
        public ?ProvisionedTopup $provisioned,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $provisioning = $payload['provisioning'] ?? null;

        return new self(
            purchase: TopupPurchase::fromPayload((array) $payload['purchase']),
            provisioned: is_array($provisioning)
                ? ProvisionedTopup::fromPayload($provisioning)
                : null,
        );
    }
}
