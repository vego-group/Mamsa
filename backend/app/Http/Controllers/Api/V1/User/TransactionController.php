<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wallet transaction ledger (backend gaps #4). Read-only history of payments,
 * refunds, top-ups and rewards. `amount` is signed at the storage layer.
 */
class TransactionController extends Controller
{
    /** GET /user/transactions */
    public function index(Request $request): JsonResponse
    {
        $transactions = $request->user()->walletTransactions()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WalletTransaction $t) => [
                'id'          => (string) $t->id,
                'ref_code'    => $t->ref_code,
                'type'        => $t->type,
                'amount'      => $t->amount,
                'description' => $t->description,
                'date'        => $t->occurred_at?->toDateString(),
                'status'      => $t->status,
            ]);

        return response()->json($transactions);
    }
}
