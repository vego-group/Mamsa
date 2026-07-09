<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\SavedCard;
use App\Services\MoyasarService;
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

    /**
     * POST /user/cards/from-token — save a chargeable card outside checkout.
     *
     * The frontend tokenises the card client-side against Moyasar's Tokens API
     * using the publishable key (card data never touches our server), then sends
     * only the token id here. We re-fetch the token with the secret key to
     * validate it and read the card metadata — nothing client-supplied is
     * trusted beyond the token id itself.
     */
    public function storeFromToken(Request $request, MoyasarService $moyasar): JsonResponse
    {
        $user = $request->user();

        // Simulate mode — no gateway keys configured (never in production):
        // store the client-supplied metadata with a fake token so the flow
        // stays testable end-to-end, mirroring PaymentController::pay().
        if (blank(config('moyasar.secret_key')) && ! app()->isProduction()) {
            $data = $request->validate([
                'brand'     => ['required', 'in:visa,mastercard,mada'],
                'last4'     => ['required', 'digits:4'],
                'exp_month' => ['required', 'integer', 'between:1,12'],
                'exp_year'  => ['required', 'integer', 'min:' . now()->year, 'max:' . (now()->year + 20)],
            ]);

            $card = $this->upsertCard($user->id, $data['brand'], $data['last4'], [
                'exp_month'     => $data['exp_month'],
                'exp_year'      => $data['exp_year'],
                'moyasar_token' => 'test_tok_' . uniqid(),
            ]);

            return response()->json($this->present($card), 201);
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $remote = $moyasar->fetchToken($data['token']);

        if (! $remote) {
            return response()->json(['message' => 'رمز البطاقة غير صالح'], 422);
        }

        // Moyasar reports the scheme as brand (tokens) / company (payments).
        $brand = match ($remote['brand'] ?? $remote['company'] ?? '') {
            'visa'                 => 'visa',
            'master', 'mastercard' => 'mastercard',
            'mada'                 => 'mada',
            default                => null,
        };

        // Tokens carry last_four; fall back to a masked PAN just in case.
        $last4 = (string) ($remote['last_four']
            ?? substr(preg_replace('/\D/', '', (string) ($remote['number'] ?? '')), -4));

        if (! $brand || strlen($last4) !== 4) {
            return response()->json(['message' => 'نوع البطاقة غير مدعوم'], 422);
        }

        $card = $this->upsertCard($user->id, $brand, $last4, array_filter([
            'exp_month'     => isset($remote['month']) ? (int) $remote['month'] : null,
            'exp_year'      => isset($remote['year']) ? (int) $remote['year'] : null,
            'moyasar_token' => $remote['id'] ?? $data['token'],
        ]));

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

    /**
     * One row per physical card (user + brand + last4): re-saving refreshes the
     * token/expiry. First card — or first chargeable card — becomes the default.
     */
    private function upsertCard(int $userId, string $brand, string $last4, array $attributes): SavedCard
    {
        $card = SavedCard::updateOrCreate(
            ['user_id' => $userId, 'brand' => $brand, 'last4' => $last4],
            $attributes,
        );

        if (! SavedCard::where('user_id', $userId)->where('is_default', true)->exists()) {
            $card->update(['is_default' => true]);
        }

        return $card->refresh();
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
            // Only cards with a gateway token can be charged (quick pay).
            'chargeable' => $card->moyasar_token !== null,
        ];
    }
}
