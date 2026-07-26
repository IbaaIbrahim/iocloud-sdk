<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

final readonly class PartnerToken
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public DateTimeImmutable $expiresAt,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            accessToken: (string) $payload['access_token'],
            tokenType: (string) $payload['token_type'],
            expiresAt: new DateTimeImmutable((string) $payload['expires_at']),
        );
    }
}
