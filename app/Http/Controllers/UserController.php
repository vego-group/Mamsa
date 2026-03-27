<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name'  => 'required|string|max:255',
            'phone' => 'nullable|string',
            'email' => 'required|email',
            'current_password' => 'nullable',
            'password' => 'nullable|min:6|confirmed',
        ]);

        // لو المستخدم يبغى يغير كلمة المرور
        if ($request->filled('password')) {

            // يتحقق من القديمة
            if (!Hash::check($request->current_password, $user->password)) {
                return back()->withErrors([
                    'current_password' => 'كلمة المرور الحالية غير صحيحة'
                ]);
            }

            // يعيّن كلمة مرور جديدة
            $user->password = Hash::make($request->password);
        }

        // تحديث بقية البيانات
        $user->name  = $validated['name'];
        $user->phone = $validated['phone'];
        $user->email = $validated['email'];

        $user->save();

        return back()->with('success', 'تم تحديث بياناتك بنجاح');
    }
}