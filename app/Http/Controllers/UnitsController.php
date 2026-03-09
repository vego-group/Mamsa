<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class UnitsController extends Controller
{
    use AuthorizesRequests;

    /**
     * عرض قائمة الوحدات:
     * - super_admin: يشوف كل الوحدات.
     * - admin: يشوف وحداته فقط (التي يملكها).
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Unit::class);

        $q = trim((string) $request->get('q', ''));

        $query = Unit::query()
            ->with('owner')
            ->when($q !== '', function ($qbuilder) use ($q) {
                $qbuilder->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                });
            })
            ->orderByDesc('id');

        // admin (بدون super_admin) يشوف وحداته فقط
        if ($request->user()->hasRole('admin') && !$request->user()->hasRole('super_admin')) {
            $query->where('user_id', $request->user()->id);
        }

        $units = $query->paginate(12)->withQueryString();

        return view('admin.units.index', compact('units', 'q'));
    }

    /**
     * صفحة إضافة وحدة جديدة.
     */
    public function create()
    {
        $this->authorize('create', Unit::class);

        return view('admin.units.create');
    }

    /**
     * حفظ وحدة جديدة:
     * - تُنسب للمدير (admin) الذي أنشأها.
     * - super_admin أيضًا ممكن ينشئ وحدة، وتنسب لحسابه (إن أردتِ تعديل ذلك لاحقًا يمكننا إضافة اختيار مالك).
     */
    public function store(Request $request)
    {
        $this->authorize('create', Unit::class);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['nullable', 'string', 'max:100', 'unique:units,code'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', 'in:available,unavailable,reserved'],
            'price'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $code = $validated['code'] ?? strtoupper(Str::random(8));

        Unit::create([
            'user_id'     => $request->user()->id, // ملكية للمستخدم الحالي (المشرف)
            'name'        => $validated['name'],
            'code'        => $code,
            'description' => $validated['description'] ?? null,
            'status'      => $validated['status'],
            'price'       => $validated['price'] ?? null,
        ]);

        return redirect()->route('admin.units.index')
            ->with('success', 'تم إضافة الوحدة بنجاح.');
    }

    /**
     * صفحة تعديل وحدة:
     * - admin لا يستطيع تعديل وحدة لا يملكها.
     * - super_admin يقدر يعدل أي وحدة.
     */
    public function edit(Unit $unit)
    {
        $this->authorize('update', $unit);

        return view('admin.units.edit', compact('unit'));
    }

    /**
     * تحديث بيانات وحدة.
     */
    public function update(Request $request, Unit $unit)
    {
        $this->authorize('update', $unit);

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255'],
            'code'        => ['required', 'string', 'max:100', 'unique:units,code,' . $unit->id],
            'description' => ['nullable', 'string', 'max:1000'],
            'status'      => ['required', 'in:available,unavailable,reserved'],
            'price'       => ['nullable', 'numeric', 'min:0'],
        ]);

        $unit->update($validated);

        return redirect()->route('admin.units.index')
            ->with('success', 'تم تحديث بيانات الوحدة.');
    }

    /**
     * حذف وحدة:
     * - admin لا يستطيع حذف وحدة لا يملكها.
     * - super_admin يقدر يحذف أي وحدة.
     */
    public function destroy(Unit $unit)
    {
        $this->authorize('delete', $unit);

        $unit->delete();

        return back()->with('success', 'تم حذف الوحدة.');
    }
}