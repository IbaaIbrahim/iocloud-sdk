<?php

namespace IOCloud\Laravel\Data;

/**
 * Which claim carries each identity value.
 *
 * Shared by the two halves of a federation setup: the issuer that writes the
 * claims and the identity provider row that tells the platform where to read
 * them. Pass one instance to both and they cannot drift apart.
 */
final readonly class SubjectTokenClaimNames
{
    public function __construct(
        public string $user = 'sub',
        public string $tenant = 'tenant_id',
        public string $email = 'email',
        public string $name = 'name',
    ) {
    }

    /** @param array<string, mixed> $claims */
    public static function fromArray(array $claims): self
    {
        return new self(
            user: (string) ($claims['user'] ?? 'sub'),
            tenant: (string) ($claims['tenant'] ?? 'tenant_id'),
            email: (string) ($claims['email'] ?? 'email'),
            name: (string) ($claims['name'] ?? 'name'),
        );
    }
}
