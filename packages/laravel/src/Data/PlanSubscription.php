<?php

namespace IOCloud\Laravel\Data;

use DateTimeImmutable;

/**
 * A subscription linking a subscriber to a plan for a billing period.
 *
 * `status` is `pending_payment` until activated, then `paid`. The window
 * (`subscribedFrom`/`subscribedTo`) is null while pending — it is established
 * at activation and is what makes the plan and the balance it provisions
 * active.
 */
final readonly class PlanSubscription
{
    public function __construct(
        public string $uuid,
        public string $status,
        public string $planType,
        public string $billingCycle,
        public ?DateTimeImmutable $subscribedFrom,
        public ?DateTimeImmutable $subscribedTo,
        public ?string $paymentTransactionUuid,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'paid';
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $from = $payload['subscribed_from'] ?? null;
        $to = $payload['subscribed_to'] ?? null;
        $paymentUuid = $payload['payment_transaction_uuid'] ?? null;

        return new self(
            uuid: (string) $payload['uuid'],
            status: (string) $payload['status'],
            planType: (string) $payload['plan_type'],
            billingCycle: (string) $payload['billing_cycle'],
            subscribedFrom: $from === null ? null : new DateTimeImmutable((string) $from),
            subscribedTo: $to === null ? null : new DateTimeImmutable((string) $to),
            paymentTransactionUuid: $paymentUuid === null ? null : (string) $paymentUuid,
            createdAt: new DateTimeImmutable((string) $payload['created_at']),
        );
    }
}
