<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A refund for this complaint has already been sent to the gateway and has not
 * come back yet.
 *
 * Distinct from the idempotency key check, which catches the SAME request
 * arriving twice. This catches a genuinely new request — a second execute, with
 * a fresh key, minutes later — made while the first is still in flight.
 *
 * That gap is easy to fall into: a successful execute leaves the refund
 * `pending` and the complaint `approved`, because the complaint is only closed
 * on settlement. So the screen still shows an executable complaint, and
 * settlement can be up to an hour away if the webhook does not arrive. An admin
 * who assumes the first attempt failed and clicks again would, without this
 * guard, refund the guest twice and debit the partner twice.
 */
class RefundInFlightException extends RuntimeException
{
    public function __construct(public readonly int $refundId)
    {
        parent::__construct('يوجد استرداد قيد التنفيذ على هذه الشكوى بالفعل');
    }
}
