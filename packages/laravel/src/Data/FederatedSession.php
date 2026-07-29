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
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $expiresIn = (int) $payload['expires_in'];
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

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
        );
    }
}
