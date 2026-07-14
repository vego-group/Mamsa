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
    /** @param array<string, string>|null $fields */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $fields = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage()];

        if ($this->fields !== null) {
            $error['fields'] = $this->fields;
        }

        return response()->json(['error' => $error], $this->status);
    }
}
