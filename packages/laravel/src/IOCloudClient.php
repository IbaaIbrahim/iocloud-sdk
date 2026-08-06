<?php

namespace IOCloud\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory as HttpFactory;
use IOCloud\Laravel\Data\ExternalTenantMapping;
use IOCloud\Laravel\Data\FederatedSession;
use IOCloud\Laravel\Data\IdentityProvider;
use IOCloud\Laravel\Data\PartnerToken;
use IOCloud\Laravel\Data\PlanSubscription;
use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Data\Tenant;
use IOCloud\Laravel\Data\TenantCredential;
use IOCloud\Laravel\Data\TenantPlan;
use IOCloud\Laravel\Data\TenantSubscription;
use IOCloud\Laravel\Data\TenantToken;
use IOCloud\Laravel\Exceptions\IOCloudAPIException;
use IOCloud\Laravel\Exceptions\IOCloudAuthenticationException;
use IOCloud\Laravel\Exceptions\IOCloudConfigurationException;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Exceptions\IOCloudTokenExchangeException;
use IOCloud\Laravel\Federation\SubjectTokenIssuer;
use InvalidArgumentException;

final class IOCloudClient
{
    /** RFC 8693 / RFC 7519 URNs identifying the exchange grant and token types. */
    public const TOKEN_EXCHANGE_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:token-exchange';

    public const JWT_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:jwt';

    private const DEFAULT_ALLOWED_ALGORITHMS = ['RS256'];

    private const DEFAULT_TOKEN_MAX_AGE_SECONDS = 900;

    private ?PartnerToken $partnerToken = null;

    /** @var array<string, TenantToken> */
    private array $tenantTokens = [];

    /**
     * `$tokenIssuer` is only needed for {@see federatedLogin()}; supply it and a
     * partner's login controller becomes a single call.
     *
     * The partner client credentials are checked when they are first used rather
     * than here: publishing a JWKS and exchanging a subject token need no partner
     * token, so federation works before those credentials are configured.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseUrl,
        private readonly float $timeout = 30.0,
        private readonly ?SubjectTokenIssuer $tokenIssuer = null,
    ) {
        $this->assertNotEmpty($baseUrl, 'baseUrl');
    }

    public function issuePartnerToken(bool $forceRefresh = false): PartnerToken
    {
        $this->assertPartnerCredentialsConfigured();

        if (! $forceRefresh && $this->tokenIsFresh($this->partnerToken)) {
            return $this->partnerToken;
        }

        $data = $this->request('POST', '/v1/partner/auth/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        return $this->partnerToken = PartnerToken::fromPayload($this->array($data['token']));
    }

    public function createTenant(
        string $applicationUuid,
        string $name,
        string $slug,
        string $contactEmail,
    ): Tenant {
        $data = $this->partnerRequest(
            'POST',
            "/v1/partner/applications/{$applicationUuid}/tenants",
            ['name' => $name, 'slug' => $slug, 'contact_email' => $contactEmail],
        );

        return Tenant::fromPayload($this->array($data['tenant']));
    }

    /**
     * List the tenant plans this partner offers.
     *
     * @return list<TenantPlan>
     */
    public function listTenantPlans(int $page = 1, int $limit = 25): array
    {
        $data = $this->partnerRequest(
            'GET',
            "/v1/partner/plans/tenant?page={$page}&limit={$limit}",
            null,
        );

        $plans = [];
        foreach ((array) ($data['list'] ?? []) as $plan) {
            $plans[] = TenantPlan::fromPayload((array) $plan);
        }

        return $plans;
    }

    /**
     * Put one of the partner's tenants on one of the partner's plans.
     *
     * Tenants are the partner's clients and never pay this platform, so the
     * partner owns both halves of the flow. With `$activateNow` (the default)
     * the subscription is created AND activated in one call: the billing window
     * opens and the tenant's balance is provisioned as child-cap rows — the
     * tenant's own cap from the plan's `credits`, plus one per active user from
     * `user_credits_cap`.
     *
     * Pass `$activateNow = false` to record the intent first (status
     * `pending_payment`) and call {@see activateTenantSubscription()} once the
     * client has actually paid. `$reference` is free text kept in the
     * platform's audit trail (invoice number, "included in retainer").
     */
    public function subscribeTenant(
        string $tenantUuid,
        string $planUuid,
        string $billingCycle = 'monthly',
        bool $activateNow = true,
        ?string $reference = null,
    ): TenantSubscription {
        $data = $this->partnerRequest(
            'POST',
            '/v1/partner/plans/tenant/subscriptions',
            [
                'tenant_uuid' => $tenantUuid,
                'plan_uuid' => $planUuid,
                'billing_cycle' => $billingCycle,
                'activate_now' => $activateNow,
                'reference' => $reference,
            ],
        );

        return TenantSubscription::fromPayload($data);
    }

    /**
     * Activate a pending tenant subscription and provision its balance.
     *
     * Call this after collecting payment from the tenant in your own billing
     * system. Idempotent: activating an already-active subscription returns it
     * unchanged with `provisioned` null.
     */
    public function activateTenantSubscription(
        string $subscriptionUuid,
        ?string $reference = null,
    ): TenantSubscription {
        $data = $this->partnerRequest(
            'POST',
            "/v1/partner/plans/tenant/subscriptions/{$subscriptionUuid}/activate",
            ['reference' => $reference],
        );

        return TenantSubscription::fromPayload($data);
    }

    /**
     * List every subscription held by this partner's tenants.
     *
     * @return list<PlanSubscription>
     */
    public function listTenantSubscriptions(): array
    {
        $data = $this->partnerRequest(
            'GET',
            '/v1/partner/plans/tenant/subscriptions',
            null,
        );

        $subscriptions = [];
        foreach ((array) ($data['subscriptions'] ?? []) as $subscription) {
            $subscriptions[] = PlanSubscription::fromPayload((array) $subscription);
        }

        return $subscriptions;
    }

    /**
     * Register the partner's own issuer as a trusted identity provider.
     *
     * `$jwksUrl` defaults to `<issuer>/.well-known/jwks.json`, the path this
     * package's JWKS route serves. Pass the same `$claimNames` as the
     * {@see SubjectTokenIssuer} that signs the tokens, so the two configurations
     * cannot drift apart.
     *
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedAlgorithms
     */
    public function createIdentityProvider(
        string $name,
        string $issuer,
        array $allowedAudiences,
        ?string $jwksUrl = null,
        array $allowedAlgorithms = self::DEFAULT_ALLOWED_ALGORITHMS,
        int $tokenMaxAgeSeconds = self::DEFAULT_TOKEN_MAX_AGE_SECONDS,
        bool $requireEmailVerified = false,
        bool $allowJitUsers = false,
        ?SubjectTokenClaimNames $claimNames = null,
    ): IdentityProvider {
        $normalizedIssuer = rtrim($issuer, '/');
        $claims = $claimNames ?? new SubjectTokenClaimNames();

        $data = $this->partnerRequest('POST', '/v1/partner/federation/providers', [
            'name' => $name,
            'issuer' => $normalizedIssuer,
            'jwks_url' => $jwksUrl ?? $normalizedIssuer.'/.well-known/jwks.json',
            'allowed_audiences' => $allowedAudiences,
            'allowed_algorithms' => $allowedAlgorithms,
            'token_max_age_seconds' => $tokenMaxAgeSeconds,
            'require_email_verified' => $requireEmailVerified,
            'user_claim' => $claims->user,
            'tenant_claim' => $claims->tenant,
            'email_claim' => $claims->email,
            'name_claim' => $claims->name,
            'allow_jit_users' => $allowJitUsers,
        ]);

        return IdentityProvider::fromPayload($this->array($data['provider']));
    }

    /**
     * List the identity providers registered by this partner.
     *
     * @return list<IdentityProvider>
     */
    public function listIdentityProviders(): array
    {
        $data = $this->partnerRequest('GET', '/v1/partner/federation/providers', null);

        return array_values(array_map(
            fn (mixed $provider): IdentityProvider => IdentityProvider::fromPayload(
                $this->array($provider)
            ),
            $this->array($data['providers']),
        ));
    }

    public function mapExternalTenant(
        string $providerUuid,
        string $tenantUuid,
        string $externalTenantId,
        ?string $accessToken = null,
    ): ExternalTenantMapping {
        $path = "/v1/partner/federation/providers/{$providerUuid}/tenants";
        $payload = [
            'tenant_uuid' => $tenantUuid,
            'external_tenant_id' => $externalTenantId,
        ];
        $data = $accessToken === null
            ? $this->partnerRequest('POST', $path, $payload)
            : $this->request('POST', $path, $payload, $accessToken);

        return ExternalTenantMapping::fromPayload($this->array($data['mapping']));
    }

    /**
     * The public key set to publish at `<issuer>/.well-known/jwks.json`.
     *
     * Return it straight from a route — this is the whole JWKS endpoint:
     *
     *     Route::get('/.well-known/jwks.json', fn () => IOCloud::jwks());
     *
     * The path is yours to choose; it only has to match the `jwks_url` registered
     * with IOCloud. Contains public key material only, and is safe to cache.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return $this->requireTokenIssuer('jwks')->jwks();
    }

    /**
     * The issuer URL and JWKS URL this client signs and publishes under.
     *
     * Useful for a diagnostics page, and for registering the provider without
     * repeating the values by hand.
     *
     * @return array{issuer: string, audience: string, jwks_url: string, kid: string}
     */
    public function federationDetails(): array
    {
        $tokenIssuer = $this->requireTokenIssuer('federationDetails');

        return [
            'issuer' => $tokenIssuer->issuer(),
            'audience' => $tokenIssuer->audience(),
            'jwks_url' => $tokenIssuer->jwksUrl(),
            'kid' => $tokenIssuer->signingKey()->kid(),
        ];
    }

    /**
     * Exchange a partner-signed OIDC JWT for a platform session (RFC 8693).
     *
     * Needs no partner token: the subject token is the credential, and trust is
     * decided by the identity provider its `iss` resolves to. Throws
     * {@see IOCloudTokenExchangeException} when the platform rejects the token.
     */
    public function exchangeSubjectToken(string $subjectToken): FederatedSession
    {
        $this->assertNotEmpty($subjectToken, 'subjectToken');

        // The exchange endpoint speaks the OAuth wire format, not the platform
        // envelope: a form body in, a flat JSON body out.
        $response = $this->http
            ->timeout($this->timeout)
            ->acceptJson()
            ->asForm()
            ->post(rtrim($this->baseUrl, '/').'/v1/federation/token', [
                'grant_type' => self::TOKEN_EXCHANGE_GRANT_TYPE,
                'subject_token' => $subjectToken,
                'subject_token_type' => self::JWT_TOKEN_TYPE,
            ]);

        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];

        if (! $response->successful()) {
            $errorDescription = (string) ($body['error_description'] ?? '');
            throw new IOCloudTokenExchangeException(
                statusCode: $response->status(),
                error: (string) ($body['error'] ?? 'invalid_grant'),
                errorDescription: $errorDescription !== ''
                    ? $errorDescription
                    : 'The subject token was rejected.',
            );
        }

        return FederatedSession::fromPayload($body);
    }

    /**
     * Sign a subject token for a logged-in partner user and exchange it.
     *
     * The whole partner-side login integration, in one call. Requires a
     * `SubjectTokenIssuer` on the client.
     *
     * @param array<string, mixed> $extraClaims
     */
    public function federatedLogin(
        string $subject,
        string $externalTenantId,
        ?string $email = null,
        ?string $name = null,
        bool $emailVerified = false,
        array $extraClaims = [],
    ): FederatedSession {
        return $this->exchangeSubjectToken($this->requireTokenIssuer('federatedLogin')->issue(
            subject: $subject,
            externalTenantId: $externalTenantId,
            email: $email,
            name: $name,
            emailVerified: $emailVerified,
            extraClaims: $extraClaims,
        ));
    }

    public function createTenantCredentials(
        string $tenantUuid,
        string $name = 'realestate-persona-sync',
    ): TenantCredential {
        $data = $this->partnerRequest(
            'POST',
            "/v1/partner/tenants/{$tenantUuid}/credentials",
            ['name' => $name],
        );

        return TenantCredential::fromPayload($this->array($data['credential']));
    }

    public function issueTenantToken(
        string $clientId,
        string $clientSecret,
        bool $forceRefresh = false,
    ): TenantToken {
        $cached = $this->tenantTokens[$clientId] ?? null;
        if (! $forceRefresh && $this->tokenIsFresh($cached)) {
            return $cached;
        }

        $data = $this->request('POST', '/v1/tenant/auth/token', [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $token = TenantToken::fromPayload($this->array($data['token']));

        return $this->tenantTokens[$clientId] = $token;
    }

    /** @return array<string, mixed> */
    public function setUserPersona(
        string $userUuid,
        string $persona,
        string $tenantClientId,
        string $tenantClientSecret,
    ): array {
        $path = "/v1/tenant/users/{$userUuid}/persona";
        $payload = ['persona' => $persona];
        $token = $this->issueTenantToken($tenantClientId, $tenantClientSecret);
        try {
            return $this->request('PATCH', $path, $payload, $token->accessToken);
        } catch (IOCloudAuthenticationException) {
            $token = $this->issueTenantToken($tenantClientId, $tenantClientSecret, true);

            return $this->request('PATCH', $path, $payload, $token->accessToken);
        }
    }

    /**
     * The configured token issuer, or a message naming what to configure.
     *
     * Federation is optional: an application that only provisions tenants never
     * needs a signing key, so every federation entry point checks here rather
     * than failing when the client is built.
     */
    private function requireTokenIssuer(string $calledMethod): SubjectTokenIssuer
    {
        if ($this->tokenIssuer === null) {
            throw new IOCloudFederationException(
                "{$calledMethod}() needs federation to be configured. Run"
                .' `php artisan iocloud:keys`, then set IOCLOUD_FEDERATION_ISSUER'
                .' to the public base URL IOCloud fetches your JWKS from.'
            );
        }

        return $this->tokenIssuer;
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function partnerRequest(string $method, string $path, ?array $payload): array
    {
        $token = $this->issuePartnerToken();
        try {
            return $this->request($method, $path, $payload, $token->accessToken);
        } catch (IOCloudAuthenticationException) {
            $token = $this->issuePartnerToken(true);

            return $this->request($method, $path, $payload, $token->accessToken);
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        ?array $payload,
        ?string $accessToken = null,
    ): array {
        $request = $this->http->timeout($this->timeout)->acceptJson()->asJson();
        if ($accessToken !== null) {
            $request = $request->withToken($accessToken);
        }
        // A GET carries no body; sending one is rejected by some proxies.
        $options = $payload === null ? [] : ['json' => $payload];
        $response = $request->send($method, rtrim($this->baseUrl, '/').$path, $options);
        $decoded = $response->json();
        $body = is_array($decoded) ? $decoded : [];

        if ($response->successful()) {
            return $this->array($body['data'] ?? $body);
        }

        $type = $response->status() === 401
            ? IOCloudAuthenticationException::class
            : IOCloudAPIException::class;
        throw new $type(
            statusCode: $response->status(),
            errorCode: (string) ($body['code'] ?? 'IOCLOUD_API_ERROR'),
            message: (string) ($body['message'] ?? $response->body() ?: 'IOCloud API request failed.'),
        );
    }

    private function tokenIsFresh(PartnerToken|TenantToken|null $token): bool
    {
        if ($token === null) {
            return false;
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $token->expiresAt > $now->modify('+30 seconds');
    }

    private function assertNotEmpty(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("{$name} must not be empty");
        }
    }

    /**
     * Partner client credentials, checked at the point of use.
     *
     * Only calls that need a partner token reach here — the JWKS document and the
     * RFC 8693 exchange do not — so an application can federate logins without
     * ever configuring these.
     */
    private function assertPartnerCredentialsConfigured(): void
    {
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            throw new IOCloudConfigurationException(
                'This call needs partner client credentials. Set'
                .' iocloud.client_id and iocloud.client_secret'
                .' (IOCLOUD_CLIENT_ID / IOCLOUD_CLIENT_SECRET).'
            );
        }
    }

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        if (! is_array($value)) {
            throw new IOCloudAPIException(500, 'INVALID_RESPONSE', 'Unexpected IOCloud API response.');
        }

        return $value;
    }
}
