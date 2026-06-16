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
            'email'       => ['nullable', 'email', 'max:150'],
            // National ID only for individuals, CR number only for companies.
            'national_id' => ['required_if:type,individual', 'nullable', 'string', 'max:20'],
            'cr_number'   => ['required_if:type,company', 'nullable', 'string', 'max:20'],
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
        ];
    }
}
