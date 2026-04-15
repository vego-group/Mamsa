<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;

class AdminRequestsController extends Controller
{
    public function index()
    {
        // الأفراد
        $individualUnits = Unit::where('approval_status', 'pending')
            ->whereNotNull('tourism_permit_no')
            ->with(['user'])
            ->latest()
            ->get();

        // الشركات
        $companyUnits = Unit::where('approval_status', 'pending')
            ->whereNotNull('company_license_no')
            ->with(['user'])
            ->latest()
            ->get();

        return view('Admin.requests.index', compact('individualUnits', 'companyUnits'));
    }

    public function show(Unit $unit)
    {
        $unit->load(['images', 'user', 'features']);

        return view('Admin.requests.show', compact('unit'));
    }

    public function approve(Unit $unit)
{
    $unit->update([
        'approval_status' => 'approved',
        'rejection_reason' => null,
    ]);

    return redirect()
        ->route('Admin.requests.index')
        ->with('success', '✅ تمت الموافقة على الوحدة');
}
    public function reject(Request $request, Unit $unit)
{
    $request->validate([
        'reason' => 'required|string|max:2000',
    ]);

    $unit->update([
        'approval_status' => 'rejected',
        'rejection_reason' => $request->reason,
    ]);

    return redirect()
        ->route('Admin.requests.index')
        ->with('success', '❌ تم رفض الوحدة');
}
}