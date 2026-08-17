<?php

namespace IOCloud\Laravel\Data;

/**
 * A credit bundle the partner sells to its own tenants.
 *
 * `plans` is what makes the offer differ per plan: empty means every tenant
 * sees the package, and one entry confines it to that tenant plan.
 * `validityDays` is null when the purchased credits never expire.
 */
final readonly class TopupPackage
{
    /** @param list<TopupPackagePlan> $plans */
    public function __construct(
        public string $uuid,
        public string $name,
        public int $credits,
        public int $priceCents,
        public ?int $validityDays,
        public string $status,
        public ?string $audience,
        public array $plans,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOfferedToEveryPlan(): bool
    {
        return $this->plans === [];
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $plans = [];
        foreach ((array) ($payload['plans'] ?? []) as $plan) {
            $plans[] = TopupPackagePlan::fromPayload((array) $plan);
        }

        $validityDays = $payload['validity_days'] ?? null;
        $audience = $payload['audience'] ?? null;

        return new self(
            uuid: (string) $payload['uuid'],
            name: (string) $payload['name'],
            credits: (int) $payload['credits'],
            priceCents: (int) $payload['price_cents'],
            validityDays: $validityDays === null ? null : (int) $validityDays,
            status: (string) $payload['status'],
            audience: $audience === null ? null : (string) $audience,
            plans: $plans,
        );
    }
}
