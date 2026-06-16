<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class InitiatePaymentRequest extends FormRequest
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
            'booking_id'     => ['required', 'integer', 'exists:bookings,id'],
            'payment_method' => ['nullable', 'in:mada,visa,mastercard,apple_pay,card'],
        ];
    }
}
