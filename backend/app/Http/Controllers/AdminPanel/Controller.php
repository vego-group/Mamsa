<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Exceptions\AdminPanelException;
use App\Http\Controllers\AdminPanel\Concerns\MapsSpec;
use App\Http\Controllers\Controller as BaseController;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Base for the admin-panel (Next.js) BFF controllers. Owns the contract
 * envelopes from BACKEND_SPEC.md:
 *   - actions      → { ok: true }
 *   - lists        → { items, total, page, pageSize }
 *   - validation   → 422 { message (ar), code: "VALIDATION_ERROR" }
 *   - camelCase keys everywhere (built by hand in each controller/transformer).
 */
abstract class Controller extends BaseController
{
    use MapsSpec;

    /**
     * Apply the common search + whitelist-sort + paginate machinery to a query.
     *
     * @param  array<string,mixed>   $args        from listArgs()
     * @param  list<string>          $searchable  DB columns matched with LIKE
     * @param  array<string,string>  $sortMap     spec field => DB column/alias
     * @param  array{0:string,1:string}  $default  fallback [column, dir]
     */
    protected function queryList(
        Builder $query,
        array $args,
        array $searchable,
        array $sortMap = [],
        array $default = ['id', 'desc'],
    ): LengthAwarePaginator {
        if ($args['search'] !== null && $searchable !== []) {
            $term = $args['search'];
            $query->where(function ($w) use ($term, $searchable) {
                foreach ($searchable as $col) {
                    $w->orWhere($col, 'like', "%{$term}%");
                }
            });
        }

        [$col, $dir] = $default;

        // What was ACTUALLY applied, echoed back in the envelope. An
        // unrecognised sortBy is ignored rather than rejected, and a sort that
        // silently ran the default order is indistinguishable from one that
        // worked — which is how `sortBy=commission` survived in a live client
        // for months. Clamping and saying so is the pattern; this extends it.
        $this->appliedSort = null;

        if ($args['sortBy'] !== null && isset($sortMap[$args['sortBy']])) {
            $col = $sortMap[$args['sortBy']];
            $dir = $args['sortDir'];
            $this->appliedSort = ['sortBy' => $args['sortBy'], 'sortDir' => $dir];
        }
        $query->orderBy($col, $dir);

        return $query->paginate($args['pageSize'], ['*'], 'page', $args['page']);
    }

    /** Set by queryList(); null when the request's sortBy was not honoured. */
    private ?array $appliedSort = null;

    /** Mutation response — BACKEND_SPEC §2.8. */
    protected function ok(int $status = 200): JsonResponse
    {
        return response()->json(['ok' => true], $status);
    }

    /** Paginated list envelope — BACKEND_SPEC §2.6: { items, total, page, pageSize }. */
    protected function items(LengthAwarePaginator $paginator, callable $transform): JsonResponse
    {
        return response()->json([
            'items'    => collect($paginator->items())->map($transform)->values(),
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
            // null = your sortBy was not recognised and the default order was
            // used. Additive; existing clients ignore it.
            'sortBy'   => $this->appliedSort['sortBy'] ?? null,
            'sortDir'  => $this->appliedSort['sortDir'] ?? null,
        ]);
    }

    /** @return never */
    protected function fail(string $code, string $message, int $status = 400): void
    {
        throw new AdminPanelException($code, $message, $status);
    }

    /**
     * Validate returning the contract's flat envelope on failure. The first
     * error message (Arabic) becomes `message`; code is VALIDATION_ERROR (422).
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            throw new AdminPanelException(
                'VALIDATION_ERROR',
                (string) $validator->errors()->first(),
                422,
            );
        }

        return $validator->validated();
    }

    /**
     * Common list query args. Defaults per BACKEND_SPEC §2.6: page=1, pageSize=10.
     *
     * @return array{page:int, pageSize:int, search:?string, sortBy:?string, sortDir:string}
     */
    protected function listArgs(Request $request): array
    {
        $sortDir = strtolower((string) $request->query('sortDir', 'desc'));

        return [
            'page'     => max(1, (int) $request->query('page', '1')),
            'pageSize' => min(100, max(1, (int) $request->query('pageSize', '10'))),
            'search'   => $this->cleanParam($request->query('search')),
            'sortBy'   => $this->cleanParam($request->query('sortBy')),
            'sortDir'  => $sortDir === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * The comparable tail of a phone number a human typed, or null.
     *
     * An admin reads `0551234567` off a note and the column holds
     * `+966551234567`; a literal LIKE misses. Reducing both to the last nine
     * digits makes every KSA form match every other.
     */
    protected function phoneTerm(string $term): ?string
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    /**
     * A displayed entity code reduced to its numeric id, e.g. BKG-0231 → 231.
     *
     * List rows render `BKG-0231` / `UNT-014`, which is what an admin copies —
     * but that string is derived from the id and exists in no column, so
     * searching for exactly what is on screen would otherwise find nothing.
     */
    protected function codeTerm(string $term): ?int
    {
        if (preg_match('/^[A-Za-z]{2,4}-0*(\d+)$/', trim($term), $m)) {
            return (int) $m[1];
        }

        return ctype_digit(trim($term)) ? (int) trim($term) : null;
    }

    /**
     * The frontend already strips undefined/null/''/'all' before sending, but
     * be defensive: treat those as "no filter".
     */
    protected function cleanParam(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '' || $value === 'all') {
            return null;
        }

        return (string) $value;
    }
}
