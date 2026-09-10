<?php

declare(strict_types=1);

namespace App\Support\Booking;

/**
 * Thrown inside the booking transaction when the dates turn out to be taken.
 *
 * An exception rather than a return value because it has to roll the
 * transaction back: returning a response from inside the closure would commit
 * the lock and leave the caller unsure whether anything was written.
 *
 * It carries a machine code because the two reasons — taken, or closed by the
 * partner — used to be told apart only by which Arabic sentence came back. A
 * client branching on that breaks the first time somebody improves the wording,
 * which is a failure with no error and no log line.
 */
final class UnitUnavailable extends \RuntimeException
{
    public const TAKEN = 'INSUFFICIENT_INVENTORY';

    public const BLOCKED = 'UNIT_BLOCKED';

    /**
     * @param  array<string, mixed>  $meta
     *
     * Named `reason`, not `code`: \Exception already has a non-readonly $code,
     * and redeclaring it readonly is a fatal error.
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $meta */
    public static function taken(array $meta = []): self
    {
        return new self(self::TAKEN, 'الوحدة محجوزة في هذه الفترة', $meta);
    }

    /** @param array<string, mixed> $meta */
    public static function blocked(array $meta = []): self
    {
        return new self(self::BLOCKED, 'الوحدة غير متاحة في هذه الفترة', $meta);
    }
}
