<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Admin-panel (Next.js) contract error. Renders the flat envelope the frontend
 * expects — { message, code } — distinct from the partner-dashboard envelope
 * ({ error: { code, message } }). `message` is Arabic (shown as-is by the UI),
 * `code` is a stable machine string for branching.
 *
 * @see BACKEND_SPEC.md §2.9
 */
class AdminPanelException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
        ], $this->status);
    }
}
