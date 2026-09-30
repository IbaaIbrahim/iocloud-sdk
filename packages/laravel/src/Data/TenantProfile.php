<?php

namespace IOCloud\Laravel\Data;

/**
 * The tenant a federated login creates when it is that tenant's first.
 *
 * What `createTenant()` takes, less the two ids the login already carries: the
 * tenant claim becomes the tenant's `externalId`, and the identity provider's
 * application is its application. The platform generates the slug from
 * `$name`. It travels as the subject token's `tenant_profile` claim, which the
 * platform reads only when no tenant of that application has the external id
 * and the provider allows just-in-time tenants. It never updates a tenant that
 * exists.
 */
final readonly class TenantProfile
{
    public function __construct(
        public string $name,
        public ?string $contactEmail = null,
    ) {
    }

    /**
     * The `tenant_profile` claim's value, keyed as the platform reads it:
     * `contact_email` is left out when there is none.
     *
     * @return array{name: string, contact_email?: string}
     */
    public function toClaim(): array
    {
        $claim = ['name' => $this->name];
        if ($this->contactEmail !== null) {
            $claim['contact_email'] = $this->contactEmail;
        }

        return $claim;
    }
}
