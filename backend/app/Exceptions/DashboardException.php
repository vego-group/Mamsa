<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Typed error for the partner-dashboard API. Renders the contract envelope:
 *   { "error": { "code": "...", "message": "...", "fields": {...}? } }
 */
class DashboardException extends Exception
{
    /**
     * @param  array<string, string>|null  $fields  per-input validation errors
     * @param  array<string, mixed>|null  $meta  facts the client needs to
     *                                           render the refusal — a limit, a count, a date range. Separate from
     *                                           `fields` because they answer different questions: `fields` says
     *                                           which input is wrong, `meta` says what the world looks like.
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $fields = null,
        public readonly ?array $meta = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->fields !== null) {
            $error['fields'] = $this->fields;
        }

        if ($this->meta !== null && $this->meta !== []) {
            $error['meta'] = $this->meta;
        }

        return response()->json(['error' => $error], $this->status);
    }
}
