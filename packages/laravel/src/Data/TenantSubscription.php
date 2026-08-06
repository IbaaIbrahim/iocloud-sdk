<?php

namespace IOCloud\Laravel\Data;

/**
 * A subscription plus whatever its activation provisioned.
 *
 * `provisioned` is null when this call provisioned nothing — the subscription
 * is still pending payment, or an already-active one was activated again
 * (activation is idempotent).
 */
final readonly class TenantSubscription
{
    public function __construct(
        public PlanSubscription $subscription,
        public ?ProvisionedBalance $provisioned,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $provisioning = $payload['provisioning'] ?? null;

        return new self(
            subscription: PlanSubscription::fromPayload(
                (array) $payload['subscription'],
            ),
            provisioned: is_array($provisioning)
                ? ProvisionedBalance::fromPayload($provisioning)
                : null,
        );
    }
}
