<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error raised by the cancellation engine when a booking cannot be
 * cancelled (wrong state, check-in passed, gateway failure). Renders as 422.
 */
class CancellationException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
        ], 422);
    }
}
