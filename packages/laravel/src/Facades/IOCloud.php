<?php

namespace IOCloud\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IOCloud\Laravel\IOCloudClient;

/**
 * @method static \IOCloud\Laravel\Data\PartnerToken issuePartnerToken(bool $forceRefresh = false)
 * @method static \IOCloud\Laravel\Data\Tenant createTenant(string $applicationUuid, string $name, string $slug, string $contactEmail)
 * @method static \IOCloud\Laravel\Data\IdentityProvider createIdentityProvider(string $name, string $issuer, array $allowedAudiences, ?string $jwksUrl = null, array $allowedAlgorithms = ['RS256'], int $tokenMaxAgeSeconds = 900, bool $requireEmailVerified = false, bool $allowJitUsers = false, ?\IOCloud\Laravel\Data\SubjectTokenClaimNames $claimNames = null)
 * @method static list<\IOCloud\Laravel\Data\IdentityProvider> listIdentityProviders()
 * @method static \IOCloud\Laravel\Data\ExternalTenantMapping mapExternalTenant(string $providerUuid, string $tenantUuid, string $externalTenantId, ?string $accessToken = null)
 * @method static array{keys: list<array<string, string>>} jwks()
 * @method static array{issuer: string, audience: string, jwks_url: string, kid: string} federationDetails()
 * @method static \IOCloud\Laravel\Data\FederatedSession exchangeSubjectToken(string $subjectToken)
 * @method static \IOCloud\Laravel\Data\FederatedSession federatedLogin(string $subject, string $externalTenantId, ?string $email = null, ?string $name = null, bool $emailVerified = false, array $extraClaims = [])
 * @method static \IOCloud\Laravel\Data\TenantCredential createTenantCredentials(string $tenantUuid, string $name = 'realestate-persona-sync')
 * @method static \IOCloud\Laravel\Data\TenantToken issueTenantToken(string $clientId, string $clientSecret, bool $forceRefresh = false)
 * @method static array<string, mixed> setUserPersona(string $userUuid, string $persona, string $tenantClientId, string $tenantClientSecret)
 *
 * @see IOCloudClient
 */
final class IOCloud extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IOCloudClient::class;
    }
}
