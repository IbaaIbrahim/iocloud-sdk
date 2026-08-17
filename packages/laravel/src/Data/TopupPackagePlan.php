<?php

namespace IOCloud\Laravel\Data;

/** One plan a top-up package is offered to. */
final readonly class TopupPackagePlan
{
    public function __construct(
        public string $planType,
        public string $planUuid,
        public string $planName,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self(
            planType: (string) ($payload['plan_type'] ?? ''),
            planUuid: (string) ($payload['plan_uuid'] ?? ''),
            planName: (string) ($payload['plan_name'] ?? ''),
        );
    }
}
