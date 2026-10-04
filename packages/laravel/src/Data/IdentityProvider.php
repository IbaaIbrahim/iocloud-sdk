<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;
use IOCloud\Laravel\Federation\JwksLocation;
use UnexpectedValueException;

/**
 * The platform's trust anchor for one partner issuer.
 *
 * It belongs to one of the partner's applications: a token it signs logs users
 * into that application's tenants only. Every field is an instruction to the
 * platform's token validator; a subject token overrides none of them.
 * `allowJitTenants` lets a login create its tenant from the token's
 * `tenant_profile` claim, and needs `allowJitUsers`.
 *
 * The platform fetches the issuer's keys from `issuerOrigin . jwksPath` and
 * from nowhere else. `jwksUrl` is that URL, kept for code that reads it: the
 * platform no longer sends it, so it is derived from the other two.
 */
final readonly class IdentityProvider
{
    /** The issuer's origin: the only host the platform fetches its keys from. */
    public string $issuerOrigin;

    /** Where on `$issuerOrigin` the platform fetches the issuer's keys. */
    public string $jwksPath;

    /**
     * `$allowJitTenants`, `$issuerOrigin` and `$jwksPath` are last and
     * defaulted so that code building a provider itself, such as a test
     * double, keeps working. Left out, the origin is derived from `$issuer`
     * and the path from `$jwksUrl`.
     *
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedAlgorithms
     */
    public function __construct(
        public string $uuid,
        public string $applicationUuid,
        public string $name,
        public string $issuer,
        public string $jwksUrl,
        public array $allowedAudiences,
        public array $allowedAlgorithms,
        public int $tokenMaxAgeSeconds,
        public bool $requireEmailVerified,
        public SubjectTokenClaimNames $claimNames,
        public bool $allowJitUsers,
        public string $status,
        public DateTimeImmutable $createdAt,
        public bool $allowJitTenants = false,
        ?string $issuerOrigin = null,
        ?string $jwksPath = null,
    ) {
        $this->issuerOrigin = $issuerOrigin ?? JwksLocation::originOf($issuer) ?? '';
        $this->jwksPath = $jwksPath ?? JwksLocation::pathOf($jwksUrl);
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        // What the platform fetches; it stopped sending the URL itself.
        $jwksUrl = isset($payload['jwks_url'])
            ? (string) $payload['jwks_url']
            : self::required($payload, 'issuer_origin').self::required($payload, 'jwks_path');

        return new self(
            uuid: (string) $payload['uuid'],
            applicationUuid: (string) $payload['application_uuid'],
            name: (string) $payload['name'],
            issuer: (string) $payload['issuer'],
            jwksUrl: $jwksUrl,
            // Both absent from a platform that predates them: derived.
            issuerOrigin: isset($payload['issuer_origin']) ? (string) $payload['issuer_origin'] : null,
            jwksPath: isset($payload['jwks_path']) ? (string) $payload['jwks_path'] : null,
            allowedAudiences: array_values(array_map(
                strval(...),
                (array) ($payload['allowed_audiences'] ?? []),
            )),
            allowedAlgorithms: array_values(array_map(
                strval(...),
                (array) ($payload['allowed_algorithms'] ?? []),
            )),
            tokenMaxAgeSeconds: (int) $payload['token_max_age_seconds'],
            requireEmailVerified: (bool) $payload['require_email_verified'],
            claimNames: new SubjectTokenClaimNames(
                user: (string) $payload['user_claim'],
                tenant: (string) $payload['tenant_claim'],
                email: (string) $payload['email_claim'],
                name: (string) $payload['name_claim'],
            ),
            allowJitUsers: (bool) $payload['allow_jit_users'],
            // False from a platform that predates just-in-time tenants.
            allowJitTenants: (bool) ($payload['allow_jit_tenants'] ?? false),
            status: (string) $payload['status'],
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @param array<string, mixed> $payload */
    private static function required(array $payload, string $key): string
    {
        if (! isset($payload[$key])) {
            throw new UnexpectedValueException(
                "The identity provider names no JWKS location: it has neither jwks_url nor {$key}."
            );
        }

        return (string) $payload[$key];
    }
}
