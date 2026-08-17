<?php

namespace IOCloud\Laravel\Data;

/**
 * The credit pool an activated top-up created.
 *
 * Unlike a tenant plan — which provisions caps against the partner's pool — a
 * tenant top-up creates a pool the tenant owns outright, spent before the
 * partner's own credits.
 */
final readonly class ProvisionedTopup
{
    public function __construct(
        public bool $poolCreated,
        public int $poolCredits,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            poolCreated: (bool) ($payload['pool_created'] ?? false),
            poolCredits: (int) ($payload['pool_credits'] ?? 0),
        );
    }
}
