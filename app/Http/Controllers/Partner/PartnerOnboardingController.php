<?php

namespace App\Http\Controllers\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class PartnerOnboardingController extends Controller
{
    public function typeForm()
    {
        abort_unless(auth()->user()->isPartner(), 403);

        $profile = auth()->user()->partner;

        if ($profile && !empty($profile->type)) {
            return redirect()->route('partner.dashboard');
        }

        return view('pages.partner.type');
    }

    public function typeStore(Request $request)
    {
        abort_unless(auth()->user()->isPartner(), 403);

        $data = $request->validate([
            'type' => 'required|in:individual,company',
        ]);

        auth()->user()->partner()->updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'type'   => $data['type'],
                'status' => $data['type'] === 'company'
                                ? 'pending_review'
                                : 'verified',
            ]
        );

        return redirect()->route('partner.license.form');
    }

    public function dashboard()
    {
        abort_unless(auth()->user()->isPartner(), 403);

        $profile = auth()->user()->partner;

        if (!$profile || empty($profile->type)) {
            return redirect()->route('partner.type.form');
        }

        return view('pages.partner.dashboard', compact('profile'));
    }
}