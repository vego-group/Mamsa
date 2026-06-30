<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\PhoneNumber;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public contact form (§9). Stores the inquiry for the support team.
 */
class ContactController extends Controller
{
    use ApiResponse;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'    => ['required', 'string', 'min:2', 'max:100'],
            'phone'   => ['required', 'string', 'regex:/^05\d{8}$/'],
            'email'   => ['required', 'email', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ], [
            'phone.regex' => 'رقم الجوال غير صحيح (يجب أن يبدأ بـ 05).',
        ]);

        Contact::create([
            'name'    => $data['name'],
            'phone'   => PhoneNumber::toE164Ksa($data['phone']),
            'email'   => $data['email'],
            'message' => $data['message'],
        ]);

        return $this->success(null, 'تم استلام رسالتك، سنتواصل معك قريباً');
    }
}
