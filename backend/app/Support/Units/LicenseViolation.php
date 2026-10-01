<?php

declare(strict_types=1);

namespace App\Support\Units;

/**
 * A licence rule said no.
 *
 * Carries a machine code because these are the rules a partner hits most often
 * and each one has a different remedy — enter the number, upgrade the permit,
 * ask for fewer apartments. A single Arabic sentence cannot be branched on, and
 * the dashboard has to tell them which of those three to do.
 */
final class LicenseViolation extends \RuntimeException
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $meta */
    public static function of(string $reason, string $message, array $meta = []): self
    {
        return new self($reason, $message, $meta);
    }
}
