<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * The platform's trust anchor for one partner issuer.
 *
 * Every field is an instruction to the platform's token validator; a subject
 * token overrides none of them.
 */
final readonly class IdentityProvider
{
    /**
     * @param list<string> $allowedAudiences
     * @param list<string> $allowedAlgorithms
     */
    public function __construct(
        public string $uuid,
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
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            uuid: (string) $payload['uuid'],
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
            status: (string) $payload['status'],
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
