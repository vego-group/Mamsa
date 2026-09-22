<?php

declare(strict_types=1);

namespace App\Support\Booking;

/**
 * Thrown inside the booking transaction when the dates turn out to be taken.
 *
 * An exception rather than a return value because it has to roll the
 * transaction back: returning a response from inside the closure would commit
 * the lock and leave the caller unsure whether anything was written.
 */
final class UnitUnavailable extends \RuntimeException
{
}
