<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The platform session a subject token was exchanged for.
 *
 * `accessToken` is opaque — not a JWT — and is presented as a bearer credential
 * on the Gateway job APIs. There are no refresh tokens: when it expires, the
 * partner signs a new subject token and exchanges again.
 *
 * `tenantUuid` is the tenant the session belongs to, and `tenantCreated` is
 * true only for the login that created that tenant from a {@see TenantProfile}.
 * A new tenant has no plan and draws on the partner's credits uncapped, so
 * subscribe it when this is true. A platform that predates both sends neither:
 * null and false.
 */
final readonly class FederatedSession
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public string $issuedTokenType,
        public int $expiresIn,
        public DateTimeImmutable $expiresAt,
        public string $userUuid,
        public string $name,
        public string $email,
        public ?string $tenantUuid = null,
        public bool $tenantCreated = false,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $expiresIn = (int) $payload['expires_in'];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $tenantUuid = $payload['tenant_uuid'] ?? null;

        return new self(
            accessToken: (string) $payload['access_token'],
            tokenType: (string) $payload['token_type'],
            issuedTokenType: (string) $payload['issued_token_type'],
            expiresIn: $expiresIn,
            // The wire format is a relative lifetime; an absolute instant is what
            // callers need to store alongside a persisted session.
            expiresAt: $now->modify("+{$expiresIn} seconds"),
            userUuid: (string) $payload['user_uuid'],
            name: (string) $payload['name'],
            email: (string) $payload['email'],
            tenantUuid: $tenantUuid === null ? null : (string) $tenantUuid,
            tenantCreated: (bool) ($payload['tenant_created'] ?? false),
        );
    }
}
