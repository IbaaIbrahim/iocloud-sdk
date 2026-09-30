<?php

namespace IOCloud\Laravel\Data;

/**
 * A plan the partner offers its own tenants.
 *
 * `credits` is the tenant's included balance and `userCreditsCap` the per-user
 * share of it; both become child-cap rows when a subscription is activated.
 *
 * `planCode` is the partner's own code for the plan, unique among its tenant
 * plans and matched exactly: a {@see TenantProfile} names the plan its tenant
 * is created on by it. It is set in the Admin Dashboard or the partner plan
 * API, and is null for a plan without one.
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
        // Last, not beside $name: 0.6.0 adds it without moving the rest for
        // code that builds a plan positionally. Null from a platform that
        // predates it.
        public ?string $planCode = null,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $planCode = $payload['plan_code'] ?? null;

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
            planCode: $planCode === null ? null : (string) $planCode,
        );
    }
}
