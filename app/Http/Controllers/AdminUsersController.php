<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class AdminUsersController extends Controller
{
    /**
     * عرض جدول المدراء + جدول المستخدمين العاديين + بحث واحد للكل
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        // ترقيم مستقل
        $adminsPageName = 'admins_page';
        $usersPageName  = 'users_page';

        /** جدول المدراء فقط */
        $admins = User::query()
            ->whereHas('roles', fn($r) => $r->where('name', 'admin'))
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

        /** جدول المستخدمين العاديين (بدون super_admin) */
        $users = User::query()
            ->whereDoesntHave('roles', fn($r) => $r->where('name', 'admin'))
            ->whereDoesntHave('roles', fn($r) => $r->where('name', 'super_admin'))
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
     * صفحة إضافة مدير فقط
     */
    public function create()
    {
        return view('admin.users.create'); // الصفحة الآن لإضافة مدير فقط
    }

    /**
     * حفظ مدير جديد فقط
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
            'phone'     => $validated['phone'],
            'password'  => Hash::make($validated['password']),
            'is_active' => $isActive,
        ]);

        // دايم مدير
        $user->assignRole('admin');

        return redirect()->route('admin.users.index')
            ->with('success', 'تم إضافة المدير بنجاح.');
    }

    /**
     * تغيير حالة مدير (نشط / معطّل / قيد)
     */
    public function status(Request $request, int $id)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['active','inactive','pending'])],
        ]);

        $user = User::findOrFail($id);

        if (!$user->hasRole('admin')) {
            return back()->with('error','هذه العملية خاصة بالمدراء فقط.');
        }

        if ($user->hasRole('super_admin')) {
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

        if ($user->hasRole('super_admin')) {
            return back()->with('error', 'لا يمكن حذف المشرف العام.');
        }

        if (!$user->hasRole('admin')) {
            return back()->with('error', 'المستخدم ليس مديرًا.');
        }

        $user->delete();

        return back()->with('success', 'تم حذف المدير بنجاح.');
    }
}