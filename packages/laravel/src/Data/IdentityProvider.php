<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * The platform's trust anchor for one partner issuer.
 *
 * It belongs to one of the partner's applications: a token it signs logs users
 * into that application's tenants only. Every field is an instruction to the
 * platform's token validator; a subject token overrides none of them.
 * `allowJitTenants` lets a login create its tenant from the token's
 * `tenant_profile` claim, and needs `allowJitUsers`.
 */
final readonly class IdentityProvider
{
    /**
     * `$allowJitTenants` is last and defaulted so that code building a provider
     * itself, such as a test double, keeps working.
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
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            uuid: (string) $payload['uuid'],
            applicationUuid: (string) $payload['application_uuid'],
            name: (string) $payload['name'],
            issuer: (string) $payload['issuer'],
            jwksUrl: (string) $payload['jwks_url'],
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
}
