<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use App\Models\Booking;
use App\Models\User;
use App\Services\Sms\SmsProvider;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * Users (guests) — BACKEND_SPEC §5.4. Scoped to the `User` role; partners and
 * admins live under their own endpoints.
 */
class UsersController extends Controller
{
    public function __construct(private readonly SmsProvider $sms) {}

    private const SORT = [
        'totalSpent'    => 'total_spent',
        'bookingsCount' => 'bookings_count',
        'joinedAt'      => 'created_at',
        'name'          => 'name',
    ];

    public function index(Request $request): JsonResponse
    {
        $args = $this->listArgs($request);

        $query = $this->baseQuery();

        if ($status = $this->cleanParam($request->query('status'))) {
            match ($status) {
                'active'             => $query->where('is_active', true),
                'pending_activation' => $query->where('is_active', false)->whereNotNull('invited_at'),
                'disabled'           => $query->where('is_active', false)->whereNull('invited_at'),
                default              => null,
            };
        }
        if ($city = $this->cleanParam($request->query('city'))) {
            $query->whereHas('bookings', fn ($b) => $b->whereHas('unit', fn ($u) => $u->where('city', $city)));
        }

        $page = $this->queryList($query, $args, ['name', 'phone', 'email'], self::SORT, ['created_at', 'desc']);

        return $this->items($page, fn (User $u) => $this->row($u));
    }

    public function stats(): JsonResponse
    {
        $base = User::role('User', 'web');

        $total   = (clone $base)->count();
        $active  = (clone $base)->where('is_active', true)->count();
        $pending = (clone $base)->where('is_active', false)->whereNotNull('invited_at')->count();
        // Everything not-active and not-invited is disabled — keeps the invariant
        // active + pendingActivation + disabled === total exact.
        $disabled = $total - $active - $pending;

        $spend = (float) Booking::query()->revenue()
            ->whereIn('user_id', (clone $base)->select('users.id'))
            ->sum('total_amount');

        return response()->json([
            'total'             => $total,
            'active'            => $active,
            'pendingActivation' => $pending,
            'disabled'          => $disabled,
            'avgSpend'          => $total > 0 ? $this->money($spend / $total) : 0.0,
        ]);
    }

    /* ---------- mutations §5.4 ---------- */

    /** PATCH /admin/users/:id/status — { status: AccountStatus }. */
    public function status(Request $request, string $id): JsonResponse
    {
        $data = $this->validate($request, [
            'status' => ['required', Rule::in(['active', 'disabled', 'pending_activation'])],
        ], ['status.in' => 'حالة غير صالحة']);

        $user = $this->guest($id);

        $user->update(match ($data['status']) {
            'active'             => ['is_active' => true,  'invited_at' => null],
            'pending_activation' => ['is_active' => false, 'invited_at' => $user->invited_at ?? now()],
            default              => ['is_active' => false, 'invited_at' => null], // disabled
        });

        return $this->ok();
    }

    /** DELETE /admin/users/:id — 409 USER_HAS_ACTIVE_BOOKINGS if any live booking. */
    public function destroy(string $id): JsonResponse
    {
        $user = $this->guest($id);

        if ($user->bookings()->whereIn('status', ['pending', 'confirmed'])->exists()) {
            $this->fail('USER_HAS_ACTIVE_BOOKINGS', 'لا يمكن حذف مستخدم لديه حجوزات نشطة', 409);
        }

        $user->delete();

        return $this->ok();
    }

    /** POST /admin/users/invite — { phone, name? }. Creates a pending_activation guest + SMS. */
    public function invite(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'phone' => ['required', 'string', 'regex:/^(\+?9665\d{8}|05\d{8}|5\d{8})$/'],
            'name'  => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['phone.regex' => 'رقم جوال غير صالح']);

        $phone = PhoneNumber::toE164Ksa($data['phone']);

        if (User::where('phone', $phone)->exists()) {
            $this->fail('CONFLICT', 'هذا الرقم مسجّل بالفعل', 409);
        }

        $user = User::create([
            'name'       => $data['name'] ?? null,
            'phone'      => $phone,
            'is_active'  => false,
            'invited_at' => now(),
        ]);
        // Resolve the role for the `web` guard explicitly — under the active
        // admin-panel session guard a bare role name resolves the wrong guard.
        $user->assignRole(Role::findByName('User', 'web'));

        $this->sendInviteSms($phone, 'تمت دعوتك للانضمام إلى منصة ممسى. حمّل التطبيق وسجّل الدخول برقم جوالك.');

        return $this->ok();
    }

    /* ---------- helpers ---------- */

    private function guest(string $id): User
    {
        $user = User::role('User', 'web')->whereKey($id)->first();

        if (! $user) {
            $this->fail('NOT_FOUND', 'المستخدم غير موجود', 404);
        }

        return $user;
    }

    /** Best-effort invite SMS; a gateway failure never fails the mutation. */
    private function sendInviteSms(string $phone, string $message): void
    {
        try {
            $this->sms->send($phone, $message, config('sms.sender_id'));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function show(string $id): JsonResponse
    {
        $u = $this->baseQuery()->whereKey($id)->first();

        if (! $u) {
            $this->fail('NOT_FOUND', 'المستخدم غير موجود', 404);
        }

        $count = (int) $u->bookings_count;

        return response()->json(array_merge($this->row($u), [
            'avgBookingValue' => $count > 0 ? $this->money((float) $u->total_spent / $count) : 0.0,
            'activity'        => $this->activity($u),
        ]));
    }

    /** Base guest query with per-user aggregates + latest-booking city. */
    private function baseQuery()
    {
        return User::query()->role('User', 'web')
            ->withCount('bookings')
            ->withCount(['bookings as active_bookings_count' => fn ($q) => $q->whereIn('status', ['pending', 'confirmed'])])
            ->withSum(['bookings as total_spent' => fn ($q) => $q->whereIn('status', Booking::REVENUE_STATUSES)], 'total_amount')
            ->addSelect(['city' => Booking::query()
                ->select('units.city')
                ->join('units', 'units.id', '=', 'bookings.unit_id')
                ->whereColumn('bookings.user_id', 'users.id')
                ->latest('bookings.created_at')
                ->limit(1)]);
    }

    /** @return array<string, mixed> */
    private function row(User $u): array
    {
        return [
            'id'                => (string) $u->id,
            'code'              => $this->code('USR', $u->id, 4),
            'name'              => $u->name ?? '',
            'email'             => $u->email,
            'phone'             => (string) $u->phone,
            'city'              => $u->city ?? '',
            'bookingsCount'     => (int) $u->bookings_count,
            'totalSpent'        => $this->money($u->total_spent),
            'joinedAt'          => $this->iso($u->created_at),
            'status'            => $this->accountStatus($u),
            'hasActiveBookings' => (int) $u->active_bookings_count > 0,
        ];
    }

    /** Derived activity from real timestamps (no separate audit log). */
    private function activity(User $u): array
    {
        $events = [];
        if ($u->created_at) {
            $events[] = ['label' => 'إنشاء الحساب', 'at' => $this->iso($u->created_at)];
        }
        if ($u->email_verified_at) {
            $events[] = ['label' => 'توثيق البريد الإلكتروني', 'at' => $this->iso($u->email_verified_at)];
        }
        if ($first = $u->bookings()->oldest()->first()) {
            $events[] = ['label' => 'أول حجز', 'at' => $this->iso($first->created_at)];
        }
        if (($last = $u->bookings()->latest()->first()) && $first && $last->id !== $first->id) {
            $events[] = ['label' => 'آخر حجز', 'at' => $this->iso($last->created_at)];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_map(fn ($e, $i) => ['id' => (string) ($i + 1)] + $e, $events, array_keys($events));
    }
}
