<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class AdminOnboardingController extends Controller
{
    public function dashboard()
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $details = auth()->user()->AdminDetails;

        return view('pages.Admin.dashboard', compact('details'));
    }
}