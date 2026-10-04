<?php

namespace IOCloud\Laravel\Federation;

use IOCloud\Laravel\Data\SubjectTokenClaimNames;
use IOCloud\Laravel\Data\TenantProfile;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;

/** Mints the short-lived OIDC JWTs a partner exchanges for a platform session. */
final class SubjectTokenIssuer
{
    /** Subject tokens exist only to be exchanged once, right after login. */
    public const DEFAULT_TOKEN_TTL_SECONDS = 300;

    /**
     * The tenant a first login may create. Its name is fixed: the claim mapping
     * renames the identity values, never this claim.
     */
    private const TENANT_PROFILE_CLAIM = 'tenant_profile';

    /**
     * Claims whose values the issuer alone decides. The tenant profile is one of
     * them: the typed `$tenant` argument of {@see issue()} is its only way in.
     */
    private const RESERVED_CLAIMS = [
        'iss', 'aud', 'iat', 'nbf', 'exp', 'jti', self::TENANT_PROFILE_CLAIM,
    ];

    /** 128 bits of randomness, which is what makes a `jti` collision-free. */
    private const TOKEN_ID_BYTES = 16;

    private readonly string $issuer;

    private readonly SubjectTokenClaimNames $claimNames;

    public function __construct(
        private readonly FederationSigningKey $signingKey,
        string $issuer,
        private readonly string $audience,
        private readonly int $tokenTtlSeconds = self::DEFAULT_TOKEN_TTL_SECONDS,
        ?SubjectTokenClaimNames $claimNames = null,
    ) {
        if (trim($issuer) === '') {
            throw new IOCloudFederationException('issuer must not be empty');
        }
        if (trim($audience) === '') {
            throw new IOCloudFederationException('audience must not be empty');
        }
        if ($tokenTtlSeconds <= 0) {
            throw new IOCloudFederationException('tokenTtlSeconds must be positive');
        }

        $this->issuer = rtrim($issuer, '/');
        $this->claimNames = $claimNames ?? new SubjectTokenClaimNames();
    }

    /** The `iss` value; also the base of the JWKS URL. */
    public function issuer(): string
    {
        return $this->issuer;
    }

    public function audience(): string
    {
        return $this->audience;
    }

    public function claimNames(): SubjectTokenClaimNames
    {
        return $this->claimNames;
    }

    public function signingKey(): FederationSigningKey
    {
        return $this->signingKey;
    }

    /** Where this issuer serves its keys: the URL {@see jwks()} is published at. */
    public function jwksUrl(): string
    {
        return $this->issuer.'/.well-known/jwks.json';
    }

    /**
     * {@see jwksUrl()}'s path: what the platform is told to fetch from the
     * issuer's origin. Pass it as `createIdentityProvider(jwksPath: ...)`.
     */
    public function jwksPath(): string
    {
        return JwksLocation::pathOf($this->jwksUrl());
    }

    /**
     * The JWKS document to serve at {@see jwksUrl()}.
     *
     * @return array{keys: list<array<string, string>>}
     */
    public function jwks(): array
    {
        return $this->signingKey->jwks();
    }

    /**
     * Sign a subject token for one logged-in partner user.
     *
     * `$subject` must be the partner's stable, never-reused user id: it is the
     * identity key the platform stores, so reusing it for a different person
     * hands over that person's account.
     *
     * `$tenant` describes the tenant to create if this is its first login, and
     * is signed as the `tenant_profile` claim. `$externalTenantId` becomes the
     * tenant claim; it may be left out when `$tenant` carries one, and must
     * equal it when both are given.
     *
     * @param array<string, mixed> $extraClaims
     */
    public function issue(
        string $subject,
        ?string $externalTenantId = null,
        ?string $email = null,
        ?string $name = null,
        bool $emailVerified = false,
        array $extraClaims = [],
        ?TenantProfile $tenant = null,
    ): string {
        if (trim($subject) === '') {
            throw new IOCloudFederationException('subject must not be empty');
        }
        if ($tenant !== null) {
            $this->assertTenantProfileValues($tenant);
        }

        $claims = $this->standardClaims(
            $subject,
            $this->tenantClaimValue($externalTenantId, $tenant),
            $email,
            $name,
            $emailVerified,
            $tenant,
        );

        // Reserved claims stay under the issuer's control: a caller cannot widen
        // the audience, extend the lifetime, or sign an unchecked tenant profile
        // through extra claims.
        foreach ($extraClaims as $claimName => $claimValue) {
            if (in_array($claimName, self::RESERVED_CLAIMS, strict: true)) {
                throw new IOCloudFederationException(
                    "extraClaims may not override the '{$claimName}' claim."
                );
            }
            $claims[$claimName] = $claimValue;
        }

        return $this->signingKey->sign($claims);
    }

    /** A copy that emits identity values under different claim names. */
    public function withClaimNames(SubjectTokenClaimNames $claimNames): self
    {
        return new self(
            signingKey: $this->signingKey,
            issuer: $this->issuer,
            audience: $this->audience,
            tokenTtlSeconds: $this->tokenTtlSeconds,
            claimNames: $claimNames,
        );
    }

    /**
     * Refuse an empty profile value, the way an empty subject is refused.
     *
     * Only emptiness is checked, and the optional values only when given:
     * lengths are the platform's to judge.
     */
    private function assertTenantProfileValues(TenantProfile $tenant): void
    {
        if (trim($tenant->name) === '') {
            throw new IOCloudFederationException('tenant.name must not be empty');
        }
        $optionalValues = [
            'contactEmail' => $tenant->contactEmail,
            'planCode' => $tenant->planCode,
            'externalTenantId' => $tenant->externalTenantId,
        ];
        foreach ($optionalValues as $fieldName => $value) {
            if ($value !== null && trim($value) === '') {
                throw new IOCloudFederationException("tenant.{$fieldName} must not be empty");
            }
        }
    }

    /**
     * The tenant claim's value: the login's `$externalTenantId`, else its
     * profile's.
     *
     * Given twice, it must be one id: the platform finds the tenant by the
     * claim, and creates it under that id, whatever the profile said.
     */
    private function tenantClaimValue(?string $externalTenantId, ?TenantProfile $tenant): string
    {
        $profileTenantId = $tenant?->externalTenantId;
        if ($externalTenantId !== null && $profileTenantId !== null && $externalTenantId !== $profileTenantId) {
            throw new IOCloudFederationException('tenant.externalTenantId must equal externalTenantId');
        }
        $tenantId = $externalTenantId ?? $profileTenantId;
        if ($tenantId === null) {
            throw new IOCloudFederationException(
                'externalTenantId is required: pass it, or a tenant that carries it'
            );
        }
        if (trim($tenantId) === '') {
            throw new IOCloudFederationException('externalTenantId must not be empty');
        }

        return $tenantId;
    }

    /** @return array<string, mixed> */
    private function standardClaims(
        string $subject,
        string $externalTenantId,
        ?string $email,
        ?string $name,
        bool $emailVerified,
        ?TenantProfile $tenant,
    ): array {
        $issuedAt = time();
        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $issuedAt,
            'nbf' => $issuedAt,
            'exp' => $issuedAt + $this->tokenTtlSeconds,
            // A unique id per token: the platform registers it so the same token
            // cannot be exchanged twice.
            'jti' => bin2hex(random_bytes(self::TOKEN_ID_BYTES)),
            $this->claimNames->user => $subject,
            $this->claimNames->tenant => $externalTenantId,
        ];
        if ($email !== null) {
            $claims[$this->claimNames->email] = $email;
            $claims['email_verified'] = $emailVerified;
        }
        if ($name !== null) {
            $claims[$this->claimNames->name] = $name;
        }
        if ($tenant !== null) {
            $claims[self::TENANT_PROFILE_CLAIM] = $tenant->toClaim();
        }

        return $claims;
    }
}
