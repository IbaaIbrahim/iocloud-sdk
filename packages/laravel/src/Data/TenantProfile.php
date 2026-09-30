<?php

namespace IOCloud\Laravel\Data;

/**
 * The tenant a federated login creates when it is that tenant's first.
 *
 * What `createTenant()` takes, less the application, which is the identity
 * provider's. The platform generates the slug from `$name`. It travels as the
 * subject token's `tenant_profile` claim, which the platform reads only when no
 * tenant of that application has the external id and the provider allows
 * just-in-time tenants. It never updates a tenant that exists.
 *
 * `$externalTenantId` is the `$externalId` `createTenant()` takes: the
 * organisation's id in your own system. The login sends it once, as the
 * token's tenant claim, never inside `tenant_profile`, so `federatedLogin()`
 * needs no `$externalTenantId` of its own when the profile carries one, and
 * refuses one that differs.
 *
 * `$planCode`, also optional, is the one addition: the `planCode` of one of the
 * partner's {@see TenantPlan}s, matched exactly. The tenant is then created
 * subscribed to that plan, monthly and active, as `subscribeTenant()` leaves it
 * by default, or not created at all: a code none of the partner's plans has
 * refuses the login with `invalid_target`. Without one the new tenant has no
 * plan.
 */
final readonly class TenantProfile
{
    public function __construct(
        public string $name,
        public ?string $contactEmail = null,
        public ?string $planCode = null,
        // Last, so a profile built positionally keeps its meaning.
        public ?string $externalTenantId = null,
    ) {
    }

    /**
     * The `tenant_profile` claim's value, keyed as the platform reads it:
     * `contact_email` and `plan_code` are each left out when there is none.
     * `$externalTenantId` is never in it: it is the tenant claim.
     *
     * @return array{name: string, contact_email?: string, plan_code?: string}
     */
    public function toClaim(): array
    {
        $claim = ['name' => $this->name];
        if ($this->contactEmail !== null) {
            $claim['contact_email'] = $this->contactEmail;
        }
        if ($this->planCode !== null) {
            $claim['plan_code'] = $this->planCode;
        }

        return $claim;
    }
}
