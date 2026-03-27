<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use App\Models\User;
use App\Models\Booking;
use App\Models\UnitImage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UnitsController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Unit::class);

        $q          = trim((string)$request->get('q', ''));
        $status     = $request->get('status');
        $priceFrom  = $request->get('price_from');
        $priceTo    = $request->get('price_to');
        $ownerId    = $request->get('owner_id');

        $query = Unit::query()
            ->with(['owner','images'])
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->when($status, fn($qb) => $qb->where('status', $status))
            ->when($priceFrom !== null && $priceFrom !== '', fn($qb) => $qb->where('price', '>=', (float)$priceFrom))
            ->when($priceTo   !== null && $priceTo   !== '', fn($qb) => $qb->where('price', '<=', (float)$priceTo))
            ->orderByDesc('id');

        if ($request->user()->hasRole('admin') && !$request->user()->hasRole('super_admin')) {
            $query->where('user_id', $request->user()->id);
        } else {
            if ($ownerId) $query->where('user_id', $ownerId);
        }

        $units = $query->paginate(12)->withQueryString();

        $ownersList = collect();
        if ($request->user()->hasRole('super_admin')) {
            $ownersList = User::query()
                ->whereHas('roles', fn($r) => $r->whereIn('name', ['Admin','Super Admin']))
                ->orderBy('name')->get(['id','name']);
        }

        return view('admin.units.index', compact('units','q','status','priceFrom','priceTo','ownerId','ownersList'));
    }

    public function create()
    {
        $this->authorize('create', Unit::class);

        $generatedCode = $this->generateUniqueCode();
        $statuses  = ['available'=>'متاحة','unavailable'=>'غير متاحة','reserved'=>'محجوزة'];

        return view('admin.units.create', compact('statuses','generatedCode'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Unit::class);

        $validated = $request->validate([
            'name'                 => ['required','string','max:255'],
            'code'                 => ['nullable','string','max:100','unique:units,code'],
            'description'          => ['nullable','string','max:2000'],
            'status'               => ['required','in:available,unavailable,reserved'],
            'price'                => ['nullable','numeric','min:0'],
            'calendar_external_url'=> ['nullable','url','max:2048'],

            // الحقول الإضافية (اختيارية)
            'type'     => ['nullable','in:apartment,villa,studio'],
            'bedrooms' => ['nullable','integer','min:0','max:50'],
            'capacity' => ['nullable','integer','min:1','max:100'],
            'city'     => ['nullable','string','max:100'],
            'district' => ['nullable','string','max:100'],
            'lat'      => ['nullable','numeric','between:-90,90'],
            'lng'      => ['nullable','numeric','between:-180,180'],

            'images.*' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);

        $code = $validated['code'] ?? $this->generateUniqueCode();

        $unit = Unit::create([
            'user_id'              => $request->user()->id,
            'name'                 => $validated['name'],
            'code'                 => $code,
            'description'          => $validated['description'] ?? null,
            'status'               => $validated['status'],
            'price'                => $validated['price'] ?? null,
            'calendar_external_url'=> $validated['calendar_external_url'] ?? null,
            'type'                 => $validated['type'] ?? null,
            'bedrooms'             => $validated['bedrooms'] ?? null,
            'capacity'             => $validated['capacity'] ?? null,
            'city'                 => $validated['city'] ?? null,
            'district'             => $validated['district'] ?? null,
            'lat'                  => $validated['lat'] ?? null,
            'lng'                  => $validated['lng'] ?? null,
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                if (!$img) continue;
                $path = $img->store('units/'.$unit->id, 'public');
                UnitImage::create([
                    'unit_id'   => $unit->id,
                    'image_url' => $path,
                    'created_at'=> now(),
                ]);
            }
        }

        return redirect()->route('admin.units.index')->with('success', 'تم إضافة الوحدة بنجاح.');
    }

    public function edit(Unit $unit)
    {
        $this->authorize('update', $unit);
        $unit->load('images','owner');

        $statuses = ['available'=>'متاحة','unavailable'=>'غير متاحة','reserved'=>'محجوزة'];

        return view('admin.units.edit', compact('unit','statuses'));
    }

    public function update(Request $request, Unit $unit)
    {
        $this->authorize('update', $unit);

        $imagePk = Schema::hasColumn('unit_images', 'image_id') ? 'image_id' : 'id';

        $validated = $request->validate([
            'name'                 => ['required','string','max:255'],
            'description'          => ['nullable','string','max:2000'],
            'status'               => ['required','in:available,unavailable,reserved'],
            'price'                => ['nullable','numeric','min:0'],
            'calendar_external_url'=> ['nullable','url','max:2048'],

            'type'     => ['nullable','in:apartment,villa,studio'],
            'bedrooms' => ['nullable','integer','min:0','max:50'],
            'capacity' => ['nullable','integer','min:1','max:100'],
            'city'     => ['nullable','string','max:100'],
            'district' => ['nullable','string','max:100'],
            'lat'      => ['nullable','numeric','between:-90,90'],
            'lng'      => ['nullable','numeric','between:-180,180'],

            'images.*'        => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'delete_images'   => ['nullable','array'],
            'delete_images.*' => ["exists:unit_images,{$imagePk}"],
        ]);

        if (!empty($validated['delete_images'])) {
            $images = UnitImage::whereIn($imagePk, $validated['delete_images'])
                ->where('unit_id', $unit->id)->get();

            foreach ($images as $img) {
                if ($img->image_url && Storage::disk('public')->exists($img->image_url)) {
                    Storage::disk('public')->delete($img->image_url);
                }
                $img->delete();
            }
        }

        $unit->update([
            'name'                 => $validated['name'],
            'description'          => $validated['description'] ?? null,
            'status'               => $validated['status'],
            'price'                => $validated['price'] ?? null,
            'calendar_external_url'=> $validated['calendar_external_url'] ?? null,
            'type'                 => $validated['type'] ?? null,
            'bedrooms'             => $validated['bedrooms'] ?? null,
            'capacity'             => $validated['capacity'] ?? null,
            'city'                 => $validated['city'] ?? null,
            'district'             => $validated['district'] ?? null,
            'lat'                  => $validated['lat'] ?? null,
            'lng'                  => $validated['lng'] ?? null,
        ]);

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $img) {
                if (!$img) continue;
                $path = $img->store('units/'.$unit->id, 'public');
                UnitImage::create([
                    'unit_id'   => $unit->id,
                    'image_url' => $path,
                    'created_at'=> now(),
                ]);
            }
        }

        return redirect()->route('admin.units.index')->with('success', 'تم تحديث بيانات الوحدة.');
    }

    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);

        foreach ($unit->images as $img) {
            if ($img->image_url && Storage::disk('public')->exists($img->image_url)) {
                Storage::disk('public')->delete($img->image_url);
            }
            $img->delete();
        }

        $unit->delete();
        return back()->with('success', 'تم حذف الوحدة.');
    }

    public function calendarIcs(Request $request, Unit $unit, string $token)
    {
        if (!hash_equals((string)$unit->calendar_token, (string)$token)) {
            abort(404);
        }

        $busyStatuses = ['confirmed','completed','reserved'];

        $bookings = Booking::query()
            ->where('unit_id', $unit->id)
            ->whereIn('status', $busyStatuses)
            ->whereNotNull('start_date')->whereNotNull('end_date')
            ->orderBy('start_date')
            ->get(['id','start_date','end_date','status','notes']);

        $extBlocks = collect();
        if (Schema::hasTable('unit_external_blocks')) {
            $extBlocks = DB::table('unit_external_blocks')
                ->where('unit_id', $unit->id)
                ->orderBy('start_date')
                ->get(['id','start_date','end_date','source','summary']);
        }

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//Mamsa//Unit Calendar//AR';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $now = now()->utc()->format('Ymd\THis\Z');

        foreach ($bookings as $b) {
            $start = Carbon::parse($b->start_date)->format('Ymd');
            $end   = Carbon::parse($b->end_date)->addDay()->format('Ymd');
            $summary = match ($b->status) {
                'reserved'  => 'محجوز (قيد)',
                'confirmed' => 'محجوز (مؤكد)',
                'completed' => 'محجوز (مكتمل)',
                default     => 'محجوز',
            };
            $desc = trim((string)$b->notes);

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:booking-{$b->id}@mamsa";
            $lines[] = "DTSTAMP:$now";
            $lines[] = "DTSTART;VALUE=DATE:$start";
            $lines[] = "DTEND;VALUE=DATE:$end";
            $lines[] = 'SUMMARY:'.$this->icsEscape($summary);
            if ($desc !== '') $lines[] = 'DESCRIPTION:'.$this->icsEscape($desc);
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }

        foreach ($extBlocks as $blk) {
            $start = Carbon::parse($blk->start_date)->format('Ymd');
            $end   = Carbon::parse($blk->end_date)->addDay()->format('Ymd');
            $summary = $blk->summary ?: 'محجوز (خارجي)';

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:extblk-{$blk->id}@mamsa";
            $lines[] = "DTSTAMP:$now";
            $lines[] = "DTSTART;VALUE=DATE:$start";
            $lines[] = "DTEND;VALUE=DATE:$end";
            $lines[] = 'SUMMARY:'.$this->icsEscape($summary);
            $lines[] = 'STATUS:CONFIRMED';
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        $body = implode("\r\n", $lines)."\r\n";

        return response($body, 200)
            ->header('Content-Type', 'text/calendar; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="unit-'.$unit->id.'.ics"');
    }

    public function rotateCalendarToken(Unit $unit)
    {
        $this->authorize('update', $unit);
        $unit->calendar_token = Str::random(40);
        $unit->save();
        return back()->with('success','تم تجديد رابط التقويم لهذه الوحدة.');
    }

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

    private function icsEscape(string $text): string
    {
        $text = str_replace(["\\",";",",","\n","\r"], ["\\\\","\;","\,","\\n",""], $text);
        $out = '';
        for ($i = 0, $len = strlen($text); $i < $len; $i += 70) {
            $chunk = substr($text, $i, 70);
            $out .= ($out === '' ? '' : "\r\n ").$chunk;
        }
        return $out;
    }
    public function filter(Request $request)
{
    // نبدأ الاستعلام
    $query = Unit::query()->with('images');

    // 🔹 فلترة المدينة
    if ($request->city_id) {
        $query->where('city', $request->city_id);
    }

    // 🔹 فلترة نوع الوحدة (شقة / فيلا / استديو)
    if ($request->unit_type) {
        $query->where('type', $request->unit_type);
    }

    // 🔹 فلترة عدد الأشخاص
    if ($request->capacity) {
        $query->where('capacity', '>=', $request->capacity);
    }

    // 🔹 فلترة التواريخ  
    // لو عندك جدول الحجوزات
    if ($request->start_date && $request->end_date) {
        $start = $request->start_date;
        $end   = $request->end_date;

        $query->whereDoesntHave('bookings', function ($q) use ($start, $end) {
            $q->where(function ($overlap) use ($start, $end) {

                // يتعارض مع بداية ونهاية الحجز
                $overlap->whereBetween('start_date', [$start, $end])
                        ->orWhereBetween('end_date', [$start, $end])

                        // يغطي الفترة كاملة
                        ->orWhere(function ($wrap) use ($start, $end) {
                            $wrap->where('start_date', '<=', $start)
                                 ->where('end_date', '>=', $end);
                        });
            });
        });
    }

    // جلب النتائج
    $units = $query->get();

    // صفحة عرض النتائج
    return view('units.results', compact('units'));
}
}