<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use App\Models\UnitImage;
use App\Models\Booking; // ✅ رجعناه
use App\Models\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UnitsController extends Controller
{
    use AuthorizesRequests;

    /* ===============================
     * Admin Index
     * =============================== */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Unit::class);

        $q         = trim((string) $request->get('q', ''));
        $status    = $request->get('status');
        $priceFrom = $request->get('price_from');
        $priceTo   = $request->get('price_to');
        $ownerId   = $request->get('owner_id');

        $query = Unit::query()
            ->with(['images','user'])
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($sub) use ($q) {
                    $sub->where('unit_name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%");
                });
            })
            ->when($status, fn ($qb) => $qb->where('status', $status))
            ->when($priceFrom !== null && $priceFrom !== '', fn ($qb) => $qb->where('price', '>=', (float)$priceFrom))
            ->when($priceTo   !== null && $priceTo   !== '', fn ($qb) => $qb->where('price', '<=', (float)$priceTo))
            ->orderByDesc('id');

        if ($request->user()->hasRole('Admin') && ! $request->user()->hasRole('SuperAdmin')) {
            $query->where('user_id', $request->user()->id);
        } else {
            if ($ownerId) {
                $query->where('user_id', $ownerId);
            }
        }

        $units = $query->paginate(12)->withQueryString();

        $ownersList = collect();
        if ($request->user()->hasRole('SuperAdmin')) {
            $ownersList = User::query()
                ->whereHas('roles', fn ($r) => $r->whereIn('name', ['Admin','SuperAdmin']))
                ->orderBy('name')
                ->get(['id','name']);
        }

        return view('Admin.units.index', compact(
            'units','q','status','priceFrom','priceTo','ownerId','ownersList'
        ));
    }

    /* ===============================
     * Create
     * =============================== */
    public function create()
    {
        $this->authorize('create', Unit::class);

        $generatedCode = $this->generateUniqueCode();
        $features = Feature::orderBy('name')->get();

        return view('Admin.units.create', compact('generatedCode','features'));
    }

    /* ===============================
     * Store
     * =============================== */
    public function store(Request $request)
    {
        $this->authorize('create', Unit::class);

        $validated = $request->validate([
            'unit_name' => ['required','string','max:255'],
            'unit_type' => ['nullable','in:apartment,villa,studio'],
            'description' => ['nullable','string','max:2000'],
            'price' => ['nullable','numeric','min:0'],
            'capacity' => ['nullable','integer','min:1','max:100'],
            'bedrooms' => ['nullable','integer','min:0','max:50'],
            'city' => ['nullable','string','max:100'],
            'district' => ['nullable','string','max:100'],
            'lat' => ['nullable','numeric','between:-90,90'],
            'lng' => ['nullable','numeric','between:-180,180'],

            'cancellation_policy' => ['nullable','in:no_cancel,48_hours'],
            'checkin_time'  => ['nullable','date_format:H:i'],
            'checkout_time' => ['nullable','date_format:H:i'],

            'tourism_permit_no'   => ['nullable','string','max:255'],
            'company_license_no'  => ['nullable','string','max:255'],
            'tourism_permit_file' => ['required','file','mimes:pdf','max:4096'],

            'images.*' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],

            'features'   => ['nullable','array'],
            'features.*' => ['exists:features,id'],
        ]);

        $unit = Unit::create([
            'user_id' => $request->user()->id,
            'unit_name' => $validated['unit_name'],
            'unit_type' => $validated['unit_type'] ?? null,
            'code' => $this->generateUniqueCode(),

            'approval_status' => 'pending',
            'status' => 'unavailable',

            'description' => $validated['description'] ?? null,
            'price' => $validated['price'] ?? null,
            'capacity' => $validated['capacity'] ?? null,
            'bedrooms' => $validated['bedrooms'] ?? null,
            'city' => $validated['city'] ?? null,
            'district' => $validated['district'] ?? null,
            'lat' => $validated['lat'] ?? null,
            'lng' => $validated['lng'] ?? null,

            'cancellation_policy' => $validated['cancellation_policy'] ?? null,
            'checkin_time' => $validated['checkin_time'] ?? null,
            'checkout_time' => $validated['checkout_time'] ?? null,

            'tourism_permit_no'  => $validated['tourism_permit_no'] ?? null,
            'company_license_no' => $validated['company_license_no'] ?? null,

            'tourism_permit_file' => '',
        ]);

        $path = $request->file('tourism_permit_file')
            ->store("permits/{$unit->id}", 'public');

        $unit->update([
            'tourism_permit_file' => $path,
        ]);

        // الصور
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                $path = $img->store("units/{$unit->id}", 'public');

                UnitImage::create([
                    'unit_id' => $unit->id,
                    'image_url' => $path,
                ]);
            }
        }

        // features
        if ($request->filled('features')) {
            $unit->features()->sync($request->features);
        }

        return redirect()->route('Admin.units.index')
            ->with('success', 'تم إضافة الوحدة وبانتظار موافقة الإدارة');
    }

    /* ===============================
     * Edit / Update
     * =============================== */
    public function edit(Unit $unit)
    {
        $this->authorize('update', $unit);

        $unit->load('images','user','features');
        $features = Feature::orderBy('name')->get();

        return view('Admin.units.edit', compact('unit','features'));
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorize('update', $unit);

        $imagePk = Schema::hasColumn('unit_images','image_id') ? 'image_id' : 'id';

        $validated = $request->validate([
            'unit_name' => ['required'],
            'status' => ['required','in:available,unavailable'], // ✅ رجعنا reserved
            'images.*' => ['nullable','image'],

            'delete_images'   => ['nullable','array'],
            'delete_images.*' => ["exists:unit_images,{$imagePk}"],

            'features' => ['nullable','array'],
            'features.*' => ['exists:features,id'],
        ]);

        // حذف الصور
        if (!empty($validated['delete_images'])) {
            $images = UnitImage::whereIn($imagePk, $validated['delete_images'])
                ->where('unit_id', $unit->id)->get();

            foreach ($images as $img) {
                if (Storage::disk('public')->exists($img->image_url)) {
                    Storage::disk('public')->delete($img->image_url);
                }
                $img->delete();
            }
        }

        $unit->update($validated);

        // features
        $unit->features()->sync($request->features ?? []);

        // إضافة صور
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                $path = $img->store("units/{$unit->id}", 'public');

                UnitImage::create([
                    'unit_id' => $unit->id,
                    'image_url' => $path,
                ]);
            }
        }

        return redirect()->route('Admin.units.index')
            ->with('success', 'تم تحديث الوحدة');
    }

    /* ===============================
     * Delete
     * =============================== */
    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);

        foreach ($unit->images as $img) {
            if (Storage::disk('public')->exists($img->image_url)) {
                Storage::disk('public')->delete($img->image_url);
            }
            $img->delete();
        }

        $unit->delete();

        return back()->with('success','تم حذف الوحدة');
    }

    /* ===============================
     * Front Filter (رجعناه)
     * =============================== */
   public function filter(Request $request)
    {
        $query = Unit::with('images')
            // ✅ فقط المتاحة
            ->where('status', 'available')
            // ✅ فقط الموافق عليها
            ->where('approval_status', 'approved');


        // المدينة
        if ($request->city_id) {
            $query->where('city', $request->city_id);
        }

        // نوع الوحدة
        if ($request->unit_type) {

            $map = [
                'شقة'    => 'apartment',
                'فيلا'   => 'villa',
                'استديو' => 'studio'
            ];

            $normalized = $map[$request->unit_type] ?? null;

            if ($normalized) {
                $query->where('unit_type', $normalized);
            }
        }

        // عدد الأشخاص
        if ($request->capacity) {
            $query->where('capacity', '>=', $request->capacity);
        }

        // عدد الغرف
        if ($request->bedrooms) {
            $query->where('bedrooms', $request->bedrooms);
        }

        // التواريخ
        if ($request->start_date && $request->end_date) {

            $start = $request->start_date;
            $end   = $request->end_date;

            $query->whereDoesntHave('bookings', function ($q) use ($start, $end) {
                $q->where(function ($overlap) use ($start, $end) {

                    $overlap->whereBetween('start_date', [$start, $end])
                            ->orWhereBetween('end_date', [$start, $end])
                            ->orWhere(function ($wrap) use ($start, $end) {
                                $wrap->where('start_date', '<=', $start)
                                     ->where('end_date', '>=', $end);
                            });

                });
            });
        }

        $units = $query->get();

        return view('results', [
    'units' => $units,
    'title' => 'نتائج البحث'
]);
    }


    /* =======================================================
     *                 Front — Show All Units
     * ======================================================= */
    public function all()
    {
        $units = Unit::with('images')
            ->where('status', 'available')
            ->where('approval_status', 'approved')
            ->latest()
            ->paginate(12);

       return view('results', [
    'units' => $units,
    'title' => 'جميع الوحدات'
]);
    }

    /* ===============================
     * Helper
     * =============================== */
    private function generateUniqueCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (Unit::where('code', $code)->exists());

        return $code;
    }
}