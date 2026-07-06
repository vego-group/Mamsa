<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PayPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ownership is enforced in the controller query.
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'payment_id'      => ['required', 'integer', 'exists:payments,id'],
            // Moyasar.js card token (real mode). Optional in test mode.
            'token'           => ['nullable', 'string'],
            'apple_pay_token' => ['nullable', 'array'],
            // Quick pay with a previously tokenised card (ownership checked in controller).
            'saved_card_id'   => ['nullable', 'integer', 'exists:saved_cards,id'],
            'cvc'             => ['nullable', 'digits_between:3,4'],
        ];
    }
}
