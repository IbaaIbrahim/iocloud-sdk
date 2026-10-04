<?php

namespace App\Services;

use App\Support\DemoUser;
use App\Support\DemoUserDirectory;
use IOCloud\Laravel\Data\IdentityProvider;
use IOCloud\Laravel\Federation\FederationConfig;
use IOCloud\Laravel\IOCloudClient;
use RuntimeException;

/**
 * Everything this portal does with IOCloud federation, in one place.
 *
 * Worth reading as the answer to "how much code does federating cost me?" — the
 * SDK signs the token and publishes the JWKS, so what is left is looking up the
 * user. Exchanging the token is the frontend's: its chat client posts it to
 * IOCloud (RFC 8693) and receives the platform session.
 *
 * Depends on {@see FederationConfig} rather than the token issuer: none of this
 * needs the private key, so a portal with federation half-configured still gets
 * a readable error instead of a container failure.
 */
final readonly class PartnerFederation
{
    public function __construct(
        private IOCloudClient $iocloud,
        private DemoUserDirectory $users,
        private FederationConfig $federation,
    ) {
    }

    /**
     * Sign a subject token for a portal user, for the frontend to exchange.
     *
     * `federatedLogin()` signs it with this portal's key and sends nothing. A
     * real portal returns it to its frontend, whose chat client exchanges it at
     * IOCloud's `/v1/federation/token`; the demo shows it instead.
     */
    public function signSubjectToken(DemoUser $user): string
    {
        return $this->iocloud->federatedLogin(
            subject: $user->subject,
            externalTenantId: $user->externalTenantId,
            email: $user->email,
            name: $user->name,
            emailVerified: $user->emailVerified,
        );
    }

    public function requireUser(string $subject): DemoUser
    {
        $user = $this->users->find($subject);
        if ($user === null) {
            throw new RuntimeException("Unknown portal user '{$subject}'.");
        }

        return $user;
    }

    /**
     * Register this portal as a trusted issuer on IOCloud, for one application.
     *
     * A token it signs logs users into that application's tenants only. Issuer,
     * JWKS path, and claim names all come from the same configuration the SDK
     * signs and publishes with, so what is registered is exactly what this
     * portal produces. IOCloud fetches the keys from the issuer's origin and
     * that path: the JWKS route in `routes/web.php`.
     */
    public function registerAsIdentityProvider(
        string $applicationUuid,
        string $name,
        bool $requireEmailVerified,
        bool $allowJitUsers,
    ): IdentityProvider {
        return $this->iocloud->createIdentityProvider(
            applicationUuid: $applicationUuid,
            name: $name,
            issuer: $this->federation->requireIssuer(),
            allowedAudiences: [$this->federation->audience],
            requireEmailVerified: $requireEmailVerified,
            allowJitUsers: $allowJitUsers,
            claimNames: $this->federation->claimNames,
            jwksPath: $this->federation->jwksPath(),
        );
    }

    /** @return list<IdentityProvider> */
    public function registeredProviders(): array
    {
        return $this->iocloud->listIdentityProviders();
    }

    /** Find the provider row this portal's issuer already occupies, if any. */
    public function existingProvider(): ?IdentityProvider
    {
        $issuer = $this->federation->requireIssuer();
        foreach ($this->registeredProviders() as $provider) {
            if ($provider->issuer === $issuer) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Give an IOCloud tenant one of the portal's organisation ids as its external id.
     *
     * The token's tenant claim resolves to the tenant of the provider's
     * application that carries it. Until one does, the exchange fails with
     * `invalid_target`: IOCloud has no tenant to place the user in.
     */
    public function setTenantExternalId(
        string $iocloudTenantUuid,
        string $externalTenantId,
    ): void {
        $this->iocloud->setTenantExternalId(
            tenantUuid: $iocloudTenantUuid,
            externalId: $externalTenantId,
        );
    }
}
