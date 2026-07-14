<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Exceptions\DashboardException;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Base for all partner-dashboard controllers: contract envelope helpers,
 * contract pagination, ownership resolution (foreign resources → 404, never
 * 403 — don't leak existence), and camelCase-aware validation.
 */
abstract class DashboardController extends Controller
{
    protected function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json($data ?? ['ok' => true], $status);
    }

    /** Contract §0.5 — { data: [...], meta: { page, limit, total } }. */
    protected function paginated(LengthAwarePaginator $paginator, callable $transform): JsonResponse
    {
        return response()->json([
            'data' => collect($paginator->items())->map($transform)->values(),
            'meta' => [
                'page'  => $paginator->currentPage(),
                'limit' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** @return never */
    protected function fail(string $code, string $message, int $status = 400, ?array $fields = null): void
    {
        throw new DashboardException($code, $message, $status, $fields);
    }

    /**
     * Validate returning contract-shaped errors:
     * { error: { code: "VALIDATION", fields: { field: "first message" } } }
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validated(Request $request, array $rules, array $messages = []): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            $fields = collect($validator->errors()->toArray())
                ->map(fn (array $msgs) => $msgs[0])
                ->all();

            throw new DashboardException('VALIDATION', 'بيانات غير صالحة', 400, $fields);
        }

        return $validator->validated();
    }

    /** Contract pagination inputs with sane caps. @return array{0:int,1:int} */
    protected function pageArgs(Request $request): array
    {
        $page  = max(1, (int) $request->query('page', '1'));
        $limit = min(100, max(1, (int) $request->query('limit', '20')));

        return [$page, $limit];
    }

    /** The partner's own unit or a 404 that doesn't leak existence (§0.2). */
    protected function ownUnit(Request $request, string $id): Unit
    {
        $unit = $request->user()->units()->whereKey($id)->first();

        if (! $unit) {
            throw new DashboardException('UNIT_NOT_FOUND', 'الوحدة غير موجودة', 404);
        }

        return $unit;
    }

    /** The partner's own booking (via unit ownership) or a non-leaking 404. */
    protected function ownBooking(Request $request, string $id): \App\Models\Booking
    {
        $booking = \App\Models\Booking::whereKey($id)
            ->whereHas('unit', fn ($q) => $q->where('user_id', $request->user()->id))
            ->first();

        if (! $booking) {
            throw new DashboardException('BOOKING_NOT_FOUND', 'الحجز غير موجود', 404);
        }

        return $booking;
    }
}
