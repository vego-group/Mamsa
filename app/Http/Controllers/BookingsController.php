<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class BookingsController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        $this->authorize('viewAny', \App\Models\Booking::class);

        $q        = trim((string) $request->get('q', ''));
        $status   = $request->get('status');          // new | confirmed | completed | cancelled | null
        $unitId   = $request->get('unit_id');         // فلترة حسب وحدة معينة
        $dateFrom = $request->get('from');            // YYYY-MM-DD
        $dateTo   = $request->get('to');              // YYYY-MM-DD

        $query = \App\Models\Booking::query()
            ->with(['unit.user', 'customer'])
            ->when($q !== '', function ($qb) use ($q) {
                $qb->where(function ($sub) use ($q) {
                    $sub->whereHas('unit', function ($u) use ($q) {
                          $u->where('unit_name', 'like', "%{$q}%")
  ->orWhere('code', 'like', "%{$q}%");
                        })
                        ->orWhereHas('customer', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                              ->orWhere('email', 'like', "%{$q}%");
                        });
                });
            })
            ->when($status, fn($qb) => $qb->where('status', $status))
            ->when($unitId, fn($qb)   => $qb->where('unit_id', $unitId))
            ->when($dateFrom, fn($qb) => $qb->whereDate('start_date', '>=', $dateFrom))
            ->when($dateTo, fn($qb)   => $qb->whereDate('end_date', '<=', $dateTo))
            ->orderByDesc('id');

        // Admin يشوف حجوزات وحداته فقط
        if ($request->user()->hasRole('Admin') && !$request->user()->hasRole('SuperAdmin')) {
            $query->whereHas('unit', fn($u) => $u->where('user_id', $request->user()->id));
        }

        $bookings = $query->paginate(12)->withQueryString();

        // قائمة الوحدات للفلترة في أعلى الصفحة:
        $unitsList = \App\Models\Unit::query()
            ->when($request->user()->hasRole('Admin') && !$request->user()->hasRole('SuperAdmin'),
                fn($u) => $u->where('user_id', $request->user()->id))
            ->orderBy('unit_name')
->get(['id','unit_name','code']);

        return view('admin.bookings.index', compact(
            'bookings', 'q', 'status', 'unitId', 'dateFrom', 'dateTo', 'unitsList'
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Booking::class);

        $validated = $request->validate([
            'unit_id'      => ['required','exists:units,id'],
            'user_id'      => ['required','exists:users,id'], // الحاجز
            'status'       => ['required','in:new,confirmed,completed,cancelled'],
            'start_date'   => ['nullable','date'],
            'end_date'     => ['nullable','date','after_or_equal:start_date'],
            'total_amount' => ['nullable','numeric','min:0'],
            'notes'        => ['nullable','string','max:2000'],
        ]);

        // Admin لا يستطيع إنشاء حجز على وحدة لا يملكها
        $unit = Unit::findOrFail($validated['unit_id']);
        if ($request->user()->hasRole('Admin') && $unit->user_id !== $request->user()->id) {
            abort(403, 'لا يمكنك إنشاء حجز على وحدة لا تملكها.');
        }

        Booking::create($validated);

        return back()->with('success', 'تم إنشاء الحجز بنجاح.');
    }

    public function update(Request $request, Booking $booking)
    {
        $this->authorize('update', $booking);

        $validated = $request->validate([
            'status'       => ['required','in:new,confirmed,completed,cancelled'],
            'start_date'   => ['nullable','date'],
            'end_date'     => ['nullable','date','after_or_equal:start_date'],
            'total_amount' => ['nullable','numeric','min:0'],
            'notes'        => ['nullable','string','max:2000'],
        ]);

        $booking->update($validated);

        return back()->with('success', 'تم تحديث بيانات الحجز.');
    }

    public function destroy(Booking $booking)
    {
        $this->authorize('delete', $booking);

        $booking->delete();

        return back()->with('success', 'تم حذف الحجز.');
    }
}
