<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\BankDetail;
use App\Support\Iban;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The payout account — wallet contract §6. Applies to BOTH account types: an
 * individual partner with no IBAN cannot be paid at all.
 */
class BankDetailsController extends DashboardController
{
    /** GET /me/bank-details → the account, or a literal null body. */
    public function show(Request $request): JsonResponse
    {
        $bank = BankDetail::where('partner_user_id', $request->user()->id)->first();

        // 200 + null, never 404: "no account yet" is the empty form, not an
        // error state on the account page (contract §6).
        //
        // Written by hand because response()->json(null) serialises an empty
        // OBJECT — the client would read `{}` as "an account with blank fields"
        // rather than "no account".
        return $bank
            ? $this->ok($this->body($bank))
            : JsonResponse::fromJsonString('null');
    }

    /** PUT /me/bank-details */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $iban   = Iban::normalize((string) $request->input('iban'));
        $holder = trim((string) $request->input('accountHolderName'));

        if (! Iban::isValid($iban)) {
            $this->fail('INVALID_IBAN', 'رقم الآيبان غير صحيح', 422, [
                'iban' => 'يجب أن يبدأ بـ SA متبوعاً بـ 22 رقماً مع رقم تحقق صحيح',
            ]);
        }

        if (mb_strlen($holder) < 3 || mb_strlen($holder) > 120) {
            $this->fail('VALIDATION', 'بيانات غير صالحة', 422, [
                'accountHolderName' => 'اسم صاحب الحساب مطلوب (٣ إلى ١٢٠ حرفاً)',
            ]);
        }

        $bank = BankDetail::firstOrNew(['partner_user_id' => $user->id]);

        // Did anything about the ACCOUNT change? The holder name counts: a bank
        // rejects a transfer whose beneficiary name does not match, so finance
        // verified that name as much as the number.
        $changed = ! $bank->exists
            || Iban::normalize($bank->iban) !== $iban
            || trim((string) $bank->account_holder_name) !== $holder;

        $bank->fill([
            'iban'                => $iban,
            'account_holder_name' => $holder,
            'bank_name'           => Iban::bankName($iban),
        ]);

        // Any edit is a resubmission: verification drops and the old rejection
        // is cleared. Keeping the rejection would strand a partner who fixed
        // exactly what they were told to fix — "الاسم لا يطابق" is corrected by
        // changing the NAME, and if that left the account rejected there would
        // be no way out and no signal to the reviewer that anything happened.
        //
        // An identical re-save changes nothing and leaves verification intact.
        if ($changed) {
            $bank->verified             = false;
            $bank->verified_at          = null;
            $bank->verified_by_admin_id = null;
            $bank->rejection_reason     = null;
        }

        $bank->save();

        // Keep the KYC view of the IBAN in step: the admin partner screen and
        // documentsComplete() read partner_details.iban. Created with a type
        // when absent — that column is NOT NULL, and a partner who reached this
        // endpoint without a detail row would otherwise 500 here.
        if ($detail = $user->partnerDetail) {
            $detail->update(['iban' => $iban]);
        } else {
            $user->partnerDetail()->create([
                'type' => $user->hasRole('Company') ? 'company' : 'individual',
                'iban' => $iban,
            ]);
        }

        return $this->ok($this->body($bank->refresh()));
    }

    /** @return array<string, mixed> */
    private function body(BankDetail $bank): array
    {
        return [
            'iban'                => $bank->iban,
            'accountHolderName'   => $bank->account_holder_name,
            'bankName'            => $bank->bank_name,
            'verified'            => (bool) $bank->verified,
            'verifiedAt'          => $bank->verified_at?->toIso8601ZuluString(),
            'rejectionReason'     => $bank->rejection_reason,
            'updatedAt'           => $bank->updated_at?->toIso8601ZuluString(),
        ];
    }
}
