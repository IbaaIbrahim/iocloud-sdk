<?php

namespace IOCloud\Laravel\Data;

/**
 * A plan the partner offers its own tenants.
 *
 * `credits` is the tenant's included balance and `userCreditsCap` the per-user
 * share of it; both become child-cap rows when a subscription is activated.
 */
final readonly class TenantPlan
{
    public function __construct(
        public string $uuid,
        public string $name,
        public int $monthlyPriceCents,
        public int $yearlyPriceCents,
        public int $tpm,
        public int $rpm,
        public int $credits,
        public int $userCreditsCap,
        public int $userTpm,
        public int $userRpm,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            uuid: (string) $payload['uuid'],
            name: (string) $payload['name'],
            monthlyPriceCents: (int) $payload['monthly_price_cents'],
            yearlyPriceCents: (int) $payload['yearly_price_cents'],
            tpm: (int) $payload['tpm'],
            rpm: (int) $payload['rpm'],
            credits: (int) $payload['credits'],
            userCreditsCap: (int) $payload['user_credits_cap'],
            userTpm: (int) $payload['user_tpm'],
            userRpm: (int) $payload['user_rpm'],
        );
    }
}
