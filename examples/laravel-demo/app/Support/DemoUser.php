<?php

namespace App\Support;

/** One row of the partner's own user table. */
final readonly class DemoUser
{
    public function __construct(
        public string $subject,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public string $externalTenantId,
    ) {
    }

    /** @param array<string, mixed> $attributes */
    public static function fromArray(array $attributes): self
    {
        return new self(
            subject: (string) $attributes['subject'],
            name: (string) $attributes['name'],
            email: (string) $attributes['email'],
            emailVerified: (bool) ($attributes['email_verified'] ?? false),
            externalTenantId: (string) $attributes['external_tenant_id'],
        );
    }
}
