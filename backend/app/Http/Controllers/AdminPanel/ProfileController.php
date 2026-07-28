<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Http\Resources\AdminPanel\AdminProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin profile & sessions — BACKEND_SPEC §5.2.
 *
 * Sessions are cookie-based and single (we don't persist a device-session
 * registry), so we expose exactly the current session, honestly — the same
 * choice the Vue admin profile made. Revoking the current session is a 409.
 */
class ProfileController extends Controller
{
    public function show(Request $request): AdminProfileResource
    {
        return new AdminProfileResource($request->user());
    }

    public function update(Request $request): AdminProfileResource
    {
        $user = $request->user();

        $data = $this->validate($request, [
            'name'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'email'           => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'preferredLocale' => ['sometimes', 'nullable', Rule::in(['ar', 'en'])],
        ], [
            'email.unique' => 'هذا البريد مستخدم بالفعل',
            'email.email'  => 'صيغة البريد غير صحيحة',
        ]);

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }
        if (array_key_exists('preferredLocale', $data) && $data['preferredLocale']) {
            $user->preferred_locale = $data['preferredLocale'];
        }

        $user->save();

        return new AdminProfileResource($user->refresh());
    }

    /** GET /admin/profile/sessions — the current cookie session only. */
    public function sessions(Request $request): JsonResponse
    {
        $ua = (string) $request->userAgent();

        return response()->json([[
            'id'           => 'current',
            'device'       => $this->deviceOf($ua),
            'browser'      => $this->browserOf($ua),
            'city'         => '',   // not derived (no GeoIP wired) — honest empty
            'current'      => true,
            'lastActiveAt' => now()->toIso8601String(),
        ]]);
    }

    /** DELETE /admin/profile/sessions/{id} — cannot revoke the current one (409). */
    public function revokeSession(Request $request, string $id): JsonResponse
    {
        if ($id === 'current') {
            $this->fail('CONFLICT', 'لا يمكن إنهاء الجلسة الحالية', 409);
        }

        // Only the current session exists — any other id is unknown.
        $this->fail('NOT_FOUND', 'الجلسة غير موجودة', 404);
    }

    private function deviceOf(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'iPhone')             => 'iPhone',
            str_contains($ua, 'iPad')               => 'iPad',
            str_contains($ua, 'Android')            => 'Android',
            str_contains($ua, 'Windows')            => 'Windows PC',
            str_contains($ua, 'Macintosh'), str_contains($ua, 'Mac OS') => 'Mac',
            str_contains($ua, 'Linux')              => 'Linux PC',
            default                                 => 'جهاز غير معروف',
        };
    }

    private function browserOf(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg')     => 'Edge',
            str_contains($ua, 'OPR'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome')  => 'Chrome',
            str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Safari')  => 'Safari',
            default                      => 'متصفح غير معروف',
        };
    }
}
