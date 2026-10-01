<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PartnerRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint; OTP proves phone ownership.
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'type'        => ['required', 'in:individual,company'],
            'name'        => ['required', 'string', 'max:100'],
            'phone'       => ['required', 'string', 'min:8', 'max:20'],
            'code'        => ['required', 'digits_between:4,8'],
            // Required for partners — it is the address verified per FR-005.
            'email'       => ['required', 'email', 'max:150'],
            // National ID only for individuals, CR number only for companies.
            'national_id' => ['required_if:type,individual', 'nullable', 'string', 'max:20'],
            // Scan of the identity document. Registration is already OTP-verified
            // at this point, so accepting the file directly is safe and spares the
            // client an authenticated presign round-trip it cannot make yet.
            // Whether it may be OMITTED is per-environment (see the config note);
            // the type/size checks below always apply to a supplied file.
            'national_id_file' => [
                config('dashboard.require_identity_file') ? 'required_if:type,individual' : 'nullable',
                'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120',
            ],
            'cr_number'   => ['required_if:type,company', 'nullable', 'string', 'max:20'],
            // Scan of the commercial registration — the company counterpart of
            // national_id_file, and gated by the same per-environment switch.
            // A CR is usually photographed rather than scanned to PDF, so the
            // same image types are accepted.
            // ⚠️ OPTIONAL until the registration form can send it. Flipping
            // DASHBOARD_REQUIRE_CR_FILE=true before the client ships the field
            // would 422 every company registration — so it is a separate switch
            // from the identity one, not the same flag.
            'cr_file' => [
                config('dashboard.require_cr_file') ? 'required_if:type,company' : 'nullable',
                'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120',
            ],
            'device'      => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'national_id.required_if' => 'رقم الهوية الوطنية مطلوب للأفراد.',
            'cr_number.required_if'   => 'رقم السجل التجاري مطلوب للشركات.',
            'cr_file.required_if'     => 'صورة السجل التجاري مطلوبة للشركات.',
            'cr_file.mimes'           => 'صيغة الملف غير مدعومة (jpg, png, pdf).',
            'cr_file.max'             => 'حجم الملف يتجاوز 5 ميجابايت.',
        ];
    }
}
