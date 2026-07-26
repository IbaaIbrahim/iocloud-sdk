<?php

namespace IOCloud\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\Factory as HttpFactory;
use IOCloud\Laravel\Data\ExternalTenantMapping;
use IOCloud\Laravel\Data\PartnerToken;
use IOCloud\Laravel\Data\Tenant;
use IOCloud\Laravel\Data\TenantCredential;
use IOCloud\Laravel\Data\TenantToken;
use IOCloud\Laravel\Exceptions\IOCloudAPIException;
use IOCloud\Laravel\Exceptions\IOCloudAuthenticationException;
use InvalidArgumentException;

final class IOCloudClient
{
    private ?PartnerToken $partnerToken = null;

    /** @var array<string, TenantToken> */
    private array $tenantTokens = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $baseUrl,
        private readonly float $timeout = 30.0,
    ) {
        $this->assertNotEmpty($clientId, 'clientId');
        $this->assertNotEmpty($clientSecret, 'clientSecret');
        $this->assertNotEmpty($baseUrl, 'baseUrl');
    }

    public function issuePartnerToken(bool $forceRefresh = false): PartnerToken
    {
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function partnerRequest(string $method, string $path, array $payload): array
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(
        string $method,
        string $path,
        array $payload,
        ?string $accessToken = null,
    ): array {
        $request = $this->http->timeout($this->timeout)->acceptJson()->asJson();
        if ($accessToken !== null) {
            $request = $request->withToken($accessToken);
        }
        $response = $request->send($method, rtrim($this->baseUrl, '/').$path, [
            'json' => $payload,
        ]);
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

    /** @return array<string, mixed> */
    private function array(mixed $value): array
    {
        if (! is_array($value)) {
            throw new IOCloudAPIException(500, 'INVALID_RESPONSE', 'Unexpected IOCloud API response.');
        }

        return $value;
    }
}
