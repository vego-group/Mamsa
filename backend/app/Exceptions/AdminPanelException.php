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
    /**
     * @param  array<string, string>|null  $fields  field key → Arabic message
     * @param  array<string, mixed>|null  $meta  the numbers a refusal talks about
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

        // Additive, and only when there is something to carry. A licence
        // refusal says "the permit covers 8"; the console renders that number
        // from here rather than by parsing the Arabic sentence.
        if ($this->meta) {
            $payload['meta'] = $this->meta;
        }

        return response()->json($payload, $this->status);
    }
}
