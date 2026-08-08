<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\User;
use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * Admins (super-admin management). Lets a SuperAdmin grant super-admin access to
 * another phone number, and list the current admins.
 *
 * This is the first SuperAdmin-gated area of the admin panel: every action here
 * is restricted to callers holding the `SuperAdmin` role (a plain `Admin` gets
 * 403), so it cannot be used to silently self-escalate.
 */
class AdminsController extends Controller
{
    /** Same phone shape the rest of the admin panel accepts: +9665XXXXXXXX / 05XXXXXXXX / 5XXXXXXXX. */
    private const PHONE_RULE = 'regex:/^(\+?9665\d{8}|05\d{8}|5\d{8})$/';

    private const SORT = [
        'name' => 'name',
        'joinedAt' => 'created_at',
    ];

    public function __construct(private readonly SmsProvider $sms) {}

    /** GET /admin/admins — paginated list of Admin + SuperAdmin accounts. */
    public function index(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $args = $this->listArgs($request);
        $query = User::query()->role(['Admin', 'SuperAdmin'], 'web')->with('roles');

        $page = $this->queryList($query, $args, ['name', 'phone', 'email'], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (User $u) => $this->row($u));
    }

    /**
     * POST /admin/admins — { phone, name? }. Grant SuperAdmin to a phone number.
     *
     * Promotes an existing account (guest/partner) in place, or creates a new one
     * so the number can sign in through the admin OTP flow. Re-granting to a
     * number that is already a super-admin returns 409.
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireSuperAdmin($request);

        $data = $this->validate($request, [
            'phone' => ['required', 'string', self::PHONE_RULE],
            'name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['phone.regex' => 'رقم جوال غير صالح']);

        $phone = PhoneNumber::toE164Ksa($data['phone']);
        $role = Role::findByName('SuperAdmin', 'web');

        $user = User::where('phone', $phone)->first();

        if ($user && $user->hasRole($role)) {
            $this->fail('CONFLICT', 'هذا الرقم مشرف عام بالفعل', 409);
        }

        if ($user) {
            // Existing account (guest/partner/admin): grant the role and make sure
            // the account is active, otherwise the login gate would reject it.
            $user->assignRole($role);
            if (! $user->is_active) {
                $user->update(['is_active' => true]);
            }
        } else {
            // No account yet: create an active one keyed to the phone so the OTP
            // login flow (admin-login) can authenticate it.
            $user = User::create([
                'name' => $data['name'] ?? null,
                'phone' => $phone,
                'is_active' => true,
            ]);
            $user->assignRole($role);
        }

        $this->notifyGranted($phone);

        return response()->json([
            'ok' => true,
            'admin' => $this->row($user->load('roles')),
        ], 201);
    }

    /* ---------- helpers ---------- */

    /**
     * Restrict the action to SuperAdmins. Uses the explicit `web` guard because
     * the active session guard is `admin-panel`, under which a bare role name
     * would resolve against the wrong guard.
     *
     * @return void — throws (403) when the caller is not a SuperAdmin.
     */
    private function requireSuperAdmin(Request $request): void
    {
        $admin = $request->user();

        if (! $admin || ! $admin->hasRole('SuperAdmin', 'web')) {
            $this->fail('FORBIDDEN', 'هذه العملية مقصورة على المشرف العام', 403);
        }
    }

    /** Best-effort SMS; a gateway failure never fails the grant. */
    private function notifyGranted(string $phone): void
    {
        try {
            $this->sms->send(
                $phone,
                'تم منحك صلاحية مشرف عام في منصة ممسى. سجّل الدخول من لوحة الإدارة برقم جوالك.',
                config('sms.sender_id'),
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @return array<string, mixed> camelCase admin row (roles must be loaded).
     */
    private function row(User $u): array
    {
        return [
            'id' => (string) $u->id,
            'name' => $u->name ?? '',
            'email' => $u->email,
            'phone' => (string) $u->phone,
            'role' => $u->roles->contains('name', 'SuperAdmin') ? 'superadmin' : 'admin',
            'isActive' => (bool) $u->is_active,
            'memberSince' => optional($u->created_at)->toIso8601String(),
        ];
    }
}
