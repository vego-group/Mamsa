<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\SavedCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Saved cards (backend gaps #4). Stores card metadata only — brand, last 4 and
 * expiry — never the full PAN. Real Moyasar tokenisation plugs into the
 * `moyasar_token` column later without changing this contract.
 */
class CardController extends Controller
{
    /** GET /user/cards */
    public function index(Request $request): JsonResponse
    {
        $cards = $request->user()->savedCards()
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SavedCard $c) => $this->present($c));

        return response()->json($cards);
    }

    /** POST /user/cards — first card added becomes the default. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'brand'     => ['required', 'in:visa,mastercard,mada'],
            'last4'     => ['required', 'digits:4'],
            'exp_month' => ['required', 'integer', 'between:1,12'],
            'exp_year'  => ['required', 'integer', 'min:' . now()->year, 'max:' . (now()->year + 20)],
        ]);

        $user = $request->user();
        $data['is_default'] = ! $user->savedCards()->exists();

        $card = $user->savedCards()->create($data);

        return response()->json($this->present($card), 201);
    }

    /** DELETE /user/cards/{card} — promotes another card if the default is removed. */
    public function destroy(Request $request, SavedCard $card): Response
    {
        $this->authorizeOwnership($request, $card);

        $wasDefault = $card->is_default;
        $userId     = $card->user_id;
        $card->delete();

        // Promote the most recent remaining card so a default always exists.
        if ($wasDefault) {
            SavedCard::where('user_id', $userId)
                ->latest('id')
                ->first()
                ?->update(['is_default' => true]);
        }

        return response()->noContent();
    }

    /** POST /user/cards/{card}/default */
    public function setDefault(Request $request, SavedCard $card): Response
    {
        $this->authorizeOwnership($request, $card);

        DB::transaction(function () use ($card) {
            SavedCard::where('user_id', $card->user_id)->update(['is_default' => false]);
            $card->update(['is_default' => true]);
        });

        return response()->noContent();
    }

    /** Reject cards that don't belong to the caller (route-model binding is global). */
    private function authorizeOwnership(Request $request, SavedCard $card): void
    {
        abort_if($card->user_id !== $request->user()->id, 403, 'غير مصرح');
    }

    /** @return array<string, mixed> */
    private function present(SavedCard $card): array
    {
        return [
            'id'         => $card->id,
            'brand'      => $card->brand,
            'last4'      => $card->last4,
            'exp_month'  => $card->exp_month,
            'exp_year'   => $card->exp_year,
            'is_default' => $card->is_default,
        ];
    }
}
