<?php

namespace App\Services;

use App\Support\DemoUser;
use App\Support\DemoUserDirectory;
use IOCloud\Laravel\Data\FederatedSession;
use IOCloud\Laravel\Data\IdentityProvider;
use IOCloud\Laravel\Federation\FederationConfig;
use IOCloud\Laravel\IOCloudClient;
use RuntimeException;

/**
 * Everything this portal does with IOCloud federation, in one place.
 *
 * Worth reading as the answer to "how much code does federating cost me?" — the
 * SDK signs the token, publishes the JWKS, and performs the RFC 8693 exchange,
 * so what is left is looking up the user.
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
     * Log a portal user into IOCloud and return the platform session.
     *
     * One SDK call: it mints a subject token signed with this portal's key, then
     * exchanges it for the platform's opaque access token.
     */
    public function logIn(DemoUser $user): FederatedSession
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
     * Register this portal as a trusted issuer on IOCloud.
     *
     * Issuer, JWKS URL, and claim names all come from the same configuration the
     * SDK signs and publishes with, so what is registered is exactly what this
     * portal produces.
     */
    public function registerAsIdentityProvider(
        string $name,
        bool $requireEmailVerified,
        bool $allowJitUsers,
    ): IdentityProvider {
        return $this->iocloud->createIdentityProvider(
            name: $name,
            issuer: $this->federation->requireIssuer(),
            allowedAudiences: [$this->federation->audience],
            jwksUrl: $this->federation->jwksUrl(),
            requireEmailVerified: $requireEmailVerified,
            allowJitUsers: $allowJitUsers,
            claimNames: $this->federation->claimNames,
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
     * Point one of the portal's organisation ids at an IOCloud tenant.
     *
     * Until this exists the exchange fails with `invalid_target`: IOCloud has no
     * tenant to place the user in.
     */
    public function mapTenant(
        string $providerUuid,
        string $iocloudTenantUuid,
        string $externalTenantId,
    ): void {
        $this->iocloud->mapExternalTenant(
            providerUuid: $providerUuid,
            tenantUuid: $iocloudTenantUuid,
            externalTenantId: $externalTenantId,
        );
    }
}
