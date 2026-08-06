<?php

namespace IOCloud\Laravel\Data;

/**
 * The balance rows an activation created.
 *
 * A tenant subscription provisions `capsCreated` and never a pool: tenants draw
 * on their partner's credit pool, bounded by those caps. Each entry is
 * `['child' => 'tenant'|'user', 'id' => int, 'cap' => int]`.
 */
final readonly class ProvisionedBalance
{
    /** @param list<array{child: string, id: int, cap: int}> $capsCreated */
    public function __construct(
        public bool $poolCreated,
        public int $poolCredits,
        public array $capsCreated,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $caps = [];
        foreach ((array) ($payload['caps_created'] ?? []) as $cap) {
            $cap = (array) $cap;
            $caps[] = [
                'child' => (string) ($cap['child'] ?? ''),
                'id' => (int) ($cap['id'] ?? 0),
                'cap' => (int) ($cap['cap'] ?? 0),
            ];
        }

        return new self(
            poolCreated: (bool) ($payload['pool_created'] ?? false),
            poolCredits: (int) ($payload['pool_credits'] ?? 0),
            capsCreated: $caps,
        );
    }
}
