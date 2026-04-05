<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function updateProfile(Request $request)
    {
        /** @var \App\Models\User $user */
$user = Auth::user();

        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'phone'             => 'nullable|string|max:20',
            'email'             => 'required|email|max:255',
            'current_password'  => 'nullable|string',
            'password'          => 'nullable|min:6|confirmed',
            'profile_image'     => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        // تحديث المعلومات الأساسية
        $user->name  = $validated['name'];
        $user->phone = $validated['phone'] ?? $user->phone;
        $user->email = $validated['email'];

        // تغيير كلمة المرور
        if ($request->filled('password')) {

            if (!Hash::check($request->current_password, $user->password)) {
                return back()->withErrors([
                    'current_password' => 'كلمة المرور الحالية غير صحيحة'
                ]);
            }

            $user->password = Hash::make($request->password);
        }

        // تحديث صورة الملف الشخصي
        if ($request->hasFile('profile_image')) {

            // حذف القديم
            if ($user->profile_image && Storage::disk('public')->exists($user->profile_image)) {
                Storage::disk('public')->delete($user->profile_image);
            }

            // رفع الجديد
            $path = $request->file('profile_image')
                ->store('users/' . $user->id, 'public');

            $user->profile_image = $path;
        }

        $user->save();

        return back()->with('success', 'تم تحديث بياناتك بنجاح');
    }
}
