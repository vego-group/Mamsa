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
    /** @param array<string, string>|null $fields field key → Arabic message */
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
        $payload = [
            'message' => $this->getMessage(),
            'code'    => $this->errorCode,
        ];

        // Only on validation failures, and only when we can actually name the
        // offending fields. A six-step wizard that gets one flat sentence back
        // cannot tell the admin WHICH step to return to — so keys here match
        // the request body keys exactly (`amenities.0`, `photoFileIds.2`).
        if ($this->fields) {
            $payload['fields'] = $this->fields;
        }

        return response()->json($payload, $this->status);
    }
}
