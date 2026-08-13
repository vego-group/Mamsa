<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard\Stub;

use App\Http\Controllers\Dashboard\DashboardController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * STUB — contract v2.2 §6 / §2.5 bank details (both account types).
 * Fixture-backed, non-production only. PUT echoes the submitted values with
 * `verified` reset to false — mirroring the real rule that any IBAN change
 * re-triggers verification. Client sends `^SA\d{22}$`; the real server also
 * runs mod-97 (422 INVALID_IBAN) — this stub validates the regex only.
 */
class BankDetailsStubController extends DashboardController
{
    /** GET /me/bank-details → BankDetails. */
    public function show(Request $request): JsonResponse
    {
        return $this->ok([
            'iban'              => 'SA0380000000608010167519',
            'accountHolderName' => 'محمد عبدالله الشهري',
            'bankName'          => 'البنك الأهلي السعودي',
            'verified'          => true,
            'verifiedAt'        => '2026-07-01T08:00:00+03:00',
            'rejectionReason'   => null,
            'updatedAt'         => '2026-08-10T12:00:00+03:00',
        ]);
    }

    /** PUT /me/bank-details { iban, accountHolderName } → BankDetails (verified reset). */
    public function update(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'iban'              => ['required', 'regex:/^SA\d{22}$/'],
            'accountHolderName' => ['required', 'string', 'min:2', 'max:150'],
        ], [
            'iban.regex' => 'الآيبان يجب أن يبدأ بـ SA متبوعاً بـ 22 رقماً',
        ]);

        return $this->ok([
            'iban'              => $data['iban'],
            'accountHolderName' => $data['accountHolderName'],
            'bankName'          => 'البنك الأهلي السعودي', // server-derived from the IBAN bank code
            'verified'          => false,                   // any change resets verification
            'verifiedAt'        => null,
            'rejectionReason'   => null,
            'updatedAt'         => now()->toIso8601String(),
        ]);
    }
}
