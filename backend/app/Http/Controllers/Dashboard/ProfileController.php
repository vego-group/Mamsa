<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Models\Booking;
use App\Models\DashboardUpload;
use App\Models\User;
use App\Services\OtpService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Partner profile (contract §2) + company payout docs (§9.2).
 */
class ProfileController extends DashboardController
{
    public function __construct(private readonly OtpService $otp) {}

    public function show(Request $request): JsonResponse
    {
        return $this->ok(self::me($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'name'  => ['sometimes', 'string', 'min:2', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150', 'unique:users,email,'.$request->user()->id],
        ]);

        // §2.2 — phone / accountType / verificationId are NOT editable here.
        $request->user()->update([
            'name'  => isset($data['name']) ? strip_tags($data['name']) : $request->user()->name,
            'email' => $data['email'] ?? $request->user()->email,
        ]);

        return $this->ok(self::me($request->user()->fresh()));
    }

    /* ---- Phone change (§2.3) — OTP goes to the NEW number ---- */

    public function requestPhoneChange(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'newPhone' => ['required', 'regex:/^5\d{8}$/'],
        ], ['newPhone.regex' => 'رقم الجوال يجب أن يكون 9 أرقام ويبدأ بـ 5']);

        $e164 = PhoneNumber::toE164Ksa($data['newPhone']);

        if (User::where('phone', $e164)->where('id', '!=', $request->user()->id)->exists()) {
            $this->fail('PHONE_TAKEN', 'هذا الرقم مستخدم بالفعل', 409);
        }

        $this->otp->request($data['newPhone'], 'change-phone', $request->ip());

        return $this->ok();
    }

    public function verifyPhoneChange(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'newPhone' => ['required', 'regex:/^5\d{8}$/'],
            'code'     => ['required', 'digits:6'],
        ]);

        $this->otp->verify($data['newPhone'], $data['code'], 'change-phone');

        $e164 = PhoneNumber::toE164Ksa($data['newPhone']);

        // Re-check inside the verified window — a race could have claimed it.
        if (User::where('phone', $e164)->where('id', '!=', $request->user()->id)->exists()) {
            $this->fail('PHONE_TAKEN', 'هذا الرقم مستخدم بالفعل', 409);
        }

        $request->user()->update(['phone' => $e164]);

        return $this->ok(self::me($request->user()->fresh()));
    }

    /* ---- Company payout docs (§9.2) ---- */

    public function companyDocs(Request $request): JsonResponse
    {
        return $this->ok(self::docs($request->user()));
    }

    public function updateCompanyDocs(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $this->validated($request, [
            'cr'                        => ['sometimes', 'regex:/^\d{10}$/'],
            'iban'                      => ['sometimes', 'regex:/^SA\d{22}$/'],
            'authorizationLetterFileId' => ['sometimes', 'nullable', 'string'],
            'vatCertificateFileId'      => ['sometimes', 'nullable', 'string'],
            'operatorLicenseFileId'     => ['sometimes', 'nullable', 'string'],
        ], [
            'cr.regex'   => 'السجل التجاري يجب أن يكون 10 أرقام',
            'iban.regex' => 'الآيبان يجب أن يبدأ بـ SA متبوعاً بـ 22 رقماً',
        ]);

        // Each referenced file must be an upload owned by THIS partner (§0.2).
        foreach (['authorizationLetterFileId', 'vatCertificateFileId', 'operatorLicenseFileId'] as $field) {
            if (! empty($data[$field]) && ! DashboardUpload::whereKey($data[$field])
                ->where('user_id', $user->id)->where('status', 'stored')->exists()) {
                $this->fail('VALIDATION', 'بيانات غير صالحة', 400, [$field => 'ملف غير موجود']);
            }
        }

        $user->partnerDetail()->updateOrCreate(['user_id' => $user->id], array_filter([
            'cr_number'                 => $data['cr'] ?? null,
            'iban'                      => $data['iban'] ?? null,
            'authorization_letter_file' => $data['authorizationLetterFileId'] ?? null,
            'vat_certificate_file'      => $data['vatCertificateFileId'] ?? null,
            'operator_license_file'     => $data['operatorLicenseFileId'] ?? null,
        ], fn ($v) => $v !== null));

        return $this->ok(self::docs($user->fresh()));
    }

    /* ---- shared transformers ---- */

    public static function me(User $user): array
    {
        $detail = $user->partnerDetail;

        $accountState = match (true) {
            ! $user->is_active                                                    => 'suspended',
            $detail?->status === \App\Models\PartnerDetail::STATUS_APPROVED       => 'approved',
            default                                                               => 'pending',
        };

        $cancellations = self::hostCancellations($user);

        return [
            'id'                       => 'p_'.$user->id,
            'name'                     => $user->name,
            'email'                    => $user->email,
            'phone'                    => $user->phone,
            'accountType'              => $detail?->type ?? 'individual',
            'verificationId'           => $detail?->type === 'company' ? $detail?->cr_number : $detail?->national_id,
            'accountState'             => $accountState,
            'hostCancellationsLast12m' => $cancellations,
            'flagged'                  => $cancellations >= (int) config('dashboard.host_cancellation_flag_threshold'),
            'memberSince'              => $user->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Deliberately NOT memoized: a static cache keyed by user id outlives the
     * request under a persistent worker and would serve a stale count. The two
     * callers above share one local instead.
     */
    public static function hostCancellations(User $user): int
    {
        return Booking::whereHas('unit', fn ($q) => $q->where('user_id', $user->id))
            ->where('cancelled_by', 'partner')
            ->where('cancelled_at', '>=', now()->subMonths(12))
            ->count();
    }

    public static function docs(User $user): array
    {
        $d = $user->partnerDetail;

        $docs = [
            'cr'                        => $d?->cr_number,
            'iban'                      => $d?->iban,
            'authorizationLetterFileId' => $d?->authorization_letter_file,
            'vatCertificateFileId'      => $d?->vat_certificate_file,
            'operatorLicenseFileId'     => $d?->operator_license_file,
        ];

        $docs['complete'] = ! in_array(null, $docs, true) && ! in_array('', $docs, true);

        return $docs;
    }
}
