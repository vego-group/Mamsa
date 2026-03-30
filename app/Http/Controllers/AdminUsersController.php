<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AdminDetail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class AdminUsersController extends Controller
{
    /**
     * عرض جدول المدراء + جدول المستخدمين
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $adminsPageName = 'admins_page';
        $usersPageName  = 'users_page';

        // المدراء فقط
        $admins = User::query()
            ->whereHas('roles', fn($r) => $r->where('name', 'Admin'))
            ->when($q !== '', fn($query) =>
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%$q%")
                        ->orWhere('email', 'like', "%$q%")
                        ->orWhere('phone', 'like', "%$q%");
                })
            )
            ->orderByDesc('id')
            ->paginate(10, ['*'], $adminsPageName)
            ->withQueryString();

        // المستخدمين العاديين
        $users = User::query()
            ->whereDoesntHave('roles', fn($r) => $r->where('name', 'Admin'))
            ->whereDoesntHave('roles', fn($r) => $r->where('name', 'Super Admin'))
            ->when($q !== '', fn($query) =>
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%$q%")
                        ->orWhere('email', 'like', "%$q%")
                        ->orWhere('phone', 'like', "%$q%");
                })
            )
            ->orderByDesc('id')
            ->paginate(10, ['*'], $usersPageName)
            ->withQueryString();

        return view('admin.users.index', compact(
            'admins',
            'users',
            'q',
            'adminsPageName',
            'usersPageName'
        ));
    }

    /**
     * صفحة إضافة مدير
     */
    public function create()
    {
        return view('admin.users.create');
    }

    /**
     * حفظ مدير جديد
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'email'     => ['required', 'email', 'max:255', Rule::unique('users','email')],
            'phone'     => ['nullable', 'string', 'max:20'],
            'password'  => ['required', 'string', 'min:8', 'confirmed'],
            'status'    => ['required', Rule::in(['active','inactive','pending'])],
        ]);

        $isActive = match($validated['status']) {
            'active'   => true,
            'inactive' => false,
            'pending'  => null,
        };

        // إنشاء المستخدم
        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'phone'     => $validated['phone'] ?? null,
            'password'  => Hash::make($validated['password']),
            'is_active' => $isActive,
        ]);

        // تعيينه مدير
        $user->assignRole('Admin');

        return redirect()->route('admin.users.index')
            ->with('success', 'تم إضافة المدير بنجاح.');
    }

    /**
     * عرض تفاصيل المدير + admin_details
     */
    public function details($id)
    {
        $user = User::with('adminDetail')->findOrFail($id);

        return view('admin.users.details', [
            'user' => $user,
            'details' => $user->adminDetail
        ]);
    }

    /**
     * تحديث حالة مدير
     */
    public function status(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active','inactive','pending'])],
        ]);

        $user = User::findOrFail($id);

        if (!$user->hasRole('Admin')) {
            return back()->with('error','هذه العملية خاصة بالمدراء فقط.');
        }

        if ($user->hasRole('Super Admin')) {
            return back()->with('error','لا يمكن تعديل حالة المشرف العام.');
        }

        $user->is_active = match($validated['status']) {
            'active'   => true,
            'inactive' => false,
            'pending'  => null,
        };

        $user->save();

        return back()->with('success','تم تحديث حالة المدير.');
    }

    /**
     * حذف مدير
     */
    public function delete(int $id)
    {
        $user = User::findOrFail($id);

        if ($user->hasRole('Super Admin')) {
            return back()->with('error', 'لا يمكن حذف المشرف العام.');
        }

        if (!$user->hasRole('Admin')) {
            return back()->with('error', 'المستخدم ليس مديرًا.');
        }

        $user->delete();

        return back()->with('success', 'تم حذف المدير بنجاح.');
    }
}