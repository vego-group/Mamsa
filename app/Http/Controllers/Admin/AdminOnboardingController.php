<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class AdminOnboardingController extends Controller
{
    public function dashboard()
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $details = auth()->user()->adminDetails;

        return view('pages.admin.dashboard', compact('details'));
    }
}