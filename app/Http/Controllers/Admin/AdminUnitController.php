<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Unit;

class AdminUnitController extends Controller
{
    /* ===============================
        Create Page
    ============================== */
    public function create()
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        return view('pages.admin.units.create');
    }


    /* ===============================
        Store Unit
    ============================== */
    public function store(Request $request)
    {
        $user = auth()->user();

        abort_unless($user->isAdmin(), 403);

       // $profile = $user->adminDetails;

        // 🔥 التحقق
        $rules = [
            'unit_name'   => 'required|string|max:255',
            'unit_type'   => 'required|string|max:255',
            'price'       => 'required|numeric|min:0',
            'capacity'    => 'required|numeric|min:1',
            'city'        => 'required|string|max:255',
            'district'    => 'required|string|max:255',
            'description' => 'nullable|string',

            // التصريح
            'tourism_permit_no'   => 'required|string|max:255',
            'tourism_permit_file' => 'required|file|mimes:pdf',
        ];
        if ($user->adminDetails?->type === 'company') {
            $rules['company_license_no'] = 'required|string|max:255';
        }
        $request->validate($rules);

        /* ===============================
           حفظ الوحدة
        ============================== */

        $unit = Unit::create([
            'user_id'    => $user->id, // 🔥 التصحيح هنا

            'unit_name'  => $request->unit_name,
            'unit_type'  => $request->unit_type,
            'price'      => $request->price,
            'capacity'   => $request->capacity,
            'city'       => $request->city,
            'district'   => $request->district,
            'description'=> $request->description,

            'tourism_permit_no' => $request->tourism_permit_no,
            'company_license_no' => $request->company_license_no,
            'tourism_permit_file' => $request->file('tourism_permit_file')
                ->store('permits','public'),

            'approval_status' => 'pending',
        ]);

        /* ===============================
           الصور
        ============================== */

        if ($request->hasFile('images')) {

            foreach ($request->file('images') as $image) {

                $path = $image->store('units', 'public');

                \App\Models\UnitImage::create([
                    'unit_id'   => $unit->id,
                    'image_url' => $path,
                ]);
            }
        }

        return redirect()->route('admin.dashboard')
            ->with('status','تم إرسال الوحدة وهي الآن قيد المراجعة');
    }
}