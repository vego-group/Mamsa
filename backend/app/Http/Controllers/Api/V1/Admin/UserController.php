<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
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
        $query = User::with('roles');

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            $query->role(self::ROLE_GROUPS[$role] ?? [$role]);
        }

        $users = $query->latest()->paginate(20);

        // Flatten roles to plain strings for the UI.
        $users->getCollection()->transform(fn (User $u) => [
            'id'         => $u->id,
            'name'       => $u->name,
            'phone'      => $u->phone,
            'email'      => $u->email,
            'is_active'  => (bool) $u->is_active,
            'roles'      => $u->getRoleNames(),
            'role'       => $u->getRoleNames()->first(),
            'created_at' => $u->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'data'   => $users->items(),
            'meta'   => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
            ],
            'counts' => $this->counts(),
        ]);
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
