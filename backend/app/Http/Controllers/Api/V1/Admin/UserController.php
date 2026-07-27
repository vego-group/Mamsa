<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    /** Tab key → concrete role names. */
    private const ROLE_GROUPS = [
        'admins'   => ['Admin', 'SuperAdmin'],
        'partners' => ['Individual', 'Company'],
        'users'    => ['User'],
    ];

    public function index(Request $request): JsonResponse
    {
        // Shared role + search scope; cloned for both the status stats and the list.
        $scope = function (Builder $q) use ($request): void {
            if ($search = trim((string) $request->query('search', ''))) {
                $q->where(function ($w) use ($search) {
                    $w->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            if ($role = $request->query('role')) {
                $q->role(self::ROLE_GROUPS[$role] ?? [$role]);
            }
        };

        // ── list query: aggregates + latest-booking city (correlated subquery) ──
        $query = User::query()->with('roles')
            ->withCount('bookings')
            ->withSum(['bookings as total_spent' => fn ($q) => $q->where('status', 'confirmed')], 'total_amount')
            ->addSelect(['city' => Booking::query()
                ->select('units.city')
                ->join('units', 'units.id', '=', 'bookings.unit_id')
                ->whereColumn('bookings.user_id', 'users.id')
                ->latest('bookings.created_at')
                ->limit(1),
            ]);
        $scope($query);

        // Status buckets: disabled = deactivated; active = has bookings; inactive = none.
        match ($request->query('status')) {
            'disabled' => $query->where('is_active', false),
            'active'   => $query->where('is_active', true)->has('bookings'),
            'inactive' => $query->where('is_active', true)->doesntHave('bookings'),
            default    => null,
        };

        $users = $query->latest()->paginate(20);

        $users->getCollection()->transform(fn (User $u) => [
            'id'                => $u->id,
            'code'              => sprintf('USR-%03d', $u->id),
            'name'              => $u->name,
            'phone'             => $u->phone,
            'email'             => $u->email,
            'city'              => $u->city,
            'is_active'         => (bool) $u->is_active,
            'status'            => $this->statusFor($u),
            'bookings_count'    => (int) $u->bookings_count,
            'total_spent'       => round((float) $u->total_spent, 2),
            'avg_booking_value' => $u->bookings_count > 0 ? round((float) $u->total_spent / $u->bookings_count, 2) : 0.0,
            'roles'             => $u->getRoleNames(),
            'role'              => $u->getRoleNames()->first(),
            'created_at'        => $u->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data'   => $users->items(),
            'meta'   => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
            ],
            'counts' => $this->counts(),
            'stats'  => $this->statusStats($scope),
        ]);
    }

    /**
     * Full profile for the detail drawer: contact, booking stats, derived activity.
     */
    public function show(User $user): JsonResponse
    {
        $bookings = $user->bookings();
        $confirmed = (clone $bookings)->where('status', 'confirmed');

        $count = (int) $confirmed->count();
        $spent = round((float) (clone $confirmed)->sum('total_amount'), 2);

        $city = Booking::query()
            ->select('units.city')
            ->join('units', 'units.id', '=', 'bookings.unit_id')
            ->where('bookings.user_id', $user->id)
            ->latest('bookings.created_at')
            ->value('city');

        return $this->success([
            'id'         => $user->id,
            'code'       => sprintf('USR-%03d', $user->id),
            'name'       => $user->name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'city'       => $city,
            'is_active'  => (bool) $user->is_active,
            'status'     => $this->statusFor($user),
            'created_at' => $user->created_at?->toIso8601String(),
            'stats'      => [
                'total_bookings'    => $count,
                'total_spent'       => $spent,
                'avg_booking_value' => $count > 0 ? round($spent / $count, 2) : 0.0,
            ],
            'activity'   => $this->activityFor($user),
        ]);
    }

    /** Derive a small activity timeline from real timestamps (no separate log). */
    private function activityFor(User $user): array
    {
        $events = [];

        if ($user->created_at) {
            $events[] = ['type' => 'account_created', 'date' => $user->created_at->toIso8601String()];
        }
        if ($user->email_verified_at) {
            $events[] = ['type' => 'email_verified', 'date' => $user->email_verified_at->toIso8601String()];
        }

        $first = $user->bookings()->oldest()->first();
        if ($first) {
            $events[] = ['type' => 'first_booking', 'date' => $first->created_at?->toIso8601String()];
        }
        $last = $user->bookings()->latest()->first();
        if ($last && $first && $last->id !== $first->id) {
            $events[] = ['type' => 'last_booking', 'date' => $last->created_at?->toIso8601String()];
        }

        usort($events, fn ($a, $b) => strcmp((string) $b['date'], (string) $a['date']));

        return $events;
    }

    /** active | inactive | disabled — matches the list status buckets. */
    private function statusFor(User $user): string
    {
        if (! $user->is_active) {
            return 'disabled';
        }

        return ($user->bookings_count ?? $user->bookings()->count()) > 0 ? 'active' : 'inactive';
    }

    /**
     * Status counts + average spend per user, over the current role/search scope.
     *
     * @param  callable(Builder):void  $scope
     * @return array<string, int|float>
     */
    private function statusStats(callable $scope): array
    {
        $base = User::query();
        $scope($base);

        $active   = (clone $base)->where('is_active', true)->has('bookings')->count();
        $inactive = (clone $base)->where('is_active', true)->doesntHave('bookings')->count();
        $disabled = (clone $base)->where('is_active', false)->count();
        $total    = $active + $inactive + $disabled;

        $spend = Booking::where('status', 'confirmed')
            ->whereIn('user_id', (clone $base)->select('users.id'))
            ->sum('total_amount');

        return [
            'active'    => $active,
            'inactive'  => $inactive,
            'disabled'  => $disabled,
            'total'     => $total,
            'avg_spend' => $total > 0 ? round((float) $spend / $total, 2) : 0.0,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:150', 'unique:users,email'],
            'role'  => ['required', 'in:User,Individual,Company,Admin,SuperAdmin'],
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'phone'     => $data['phone'],
            'email'     => $data['email'] ?? null,
            'is_active' => true,
        ]);

        $user->syncRoles($data['role']);

        return $this->success([
            'id'        => $user->id,
            'name'      => $user->name,
            'phone'     => $user->phone,
            'email'     => $user->email,
            'is_active' => true,
            'roles'     => $user->getRoleNames(),
            'role'      => $data['role'],
        ], 'تم إنشاء المستخدم', 201);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->id === $request->user()->id) {
            return $this->error('لا يمكنك تغيير حالة حسابك الخاص', 422);
        }

        $user->update(['is_active' => $data['is_active']]);

        return $this->success(['id' => $user->id, 'is_active' => (bool) $user->is_active], 'تم تحديث الحالة');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return $this->error('لا يمكنك حذف حسابك الخاص', 422);
        }

        // Never delete the last SuperAdmin — would lock everyone out.
        if ($user->hasRole('SuperAdmin') && User::role('SuperAdmin')->count() <= 1) {
            return $this->error('لا يمكن حذف المشرف العام الوحيد', 422);
        }

        $user->delete();

        return $this->success(null, 'تم حذف المستخدم');
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        return [
            'all'      => User::count(),
            'admins'   => User::role(self::ROLE_GROUPS['admins'])->count(),
            'partners' => User::role(self::ROLE_GROUPS['partners'])->count(),
            'users'    => User::role(self::ROLE_GROUPS['users'])->count(),
        ];
    }
}
