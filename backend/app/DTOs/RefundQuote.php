<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Immutable result of evaluating a booking's cancellation snapshot against a
 * given moment in time — SRS 2.3.2. Produced by CancellationPolicyService and
 * consumed by both the preview endpoint (FR-043/044) and CancelBookingAction.
 */
final readonly class RefundQuote
{
    public function __construct(
        public bool $cancellable,
        public float $refundAmount,   // SAR to return to the guest
        public int $refundPercent,    // 0..100 applied from the matched tier
        public ?string $tierLabel,
        public int $hoursBeforeCheckin,
        public ?string $reason = null, // why cancellation is blocked, if it is
    ) {}

    /** Cancellation blocked because check-in already passed (FR-045). */
    public static function blocked(int $hoursBeforeCheckin, string $reason): self
    {
        return new self(false, 0.0, 0, null, $hoursBeforeCheckin, $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'cancellable'          => $this->cancellable,
            'refund_amount'        => $this->refundAmount,
            'refund_percent'       => $this->refundPercent,
            'tier_label'           => $this->tierLabel,
            'hours_before_checkin' => $this->hoursBeforeCheckin,
            'reason'               => $this->reason,
        ];
    }
}
