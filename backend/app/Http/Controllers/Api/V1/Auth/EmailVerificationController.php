<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\EmailVerificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FR-005 / FR-006 — partner email verification. The user is already
 * authenticated (phone OTP + partner register issued a token), so the email
 * is taken from the authenticated user, never from the request body.
 */
class EmailVerificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EmailVerificationService $emails) {}

    /** Send (or resend) the verification code to the authenticated user's email. */
    public function send(Request $request): JsonResponse
    {
        $this->emails->send($request->user());

        return $this->success(null, 'تم إرسال رمز التحقق إلى بريدك الإلكتروني');
    }

    /** Verify the submitted code and mark the email as verified. */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'digits_between:4,8'],
        ]);

        $this->emails->verify($request->user(), $validated['code']);

        return $this->success(
            new UserResource($request->user()->fresh()->load('roles')),
            'تم تأكيد البريد الإلكتروني بنجاح',
        );
    }
}
