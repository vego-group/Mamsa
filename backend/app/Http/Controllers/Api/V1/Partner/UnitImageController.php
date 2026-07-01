<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\UnitImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Partner unit gallery management. Photos are stored on the public disk under
 * units/{unit_id}/... and served via UnitImage::getUrlAttribute (asset('storage/…')).
 * Swapping FILESYSTEM_DISK to S3 later needs no controller change — the accessor
 * already passes absolute URLs through.
 */
class UnitImageController extends Controller
{
    /** Max photos a single unit may hold. */
    private const MAX_IMAGES = 10;

    /** POST /partner/units/{unit}/images — upload one or more photos. */
    public function store(Request $request, Unit $unit): JsonResponse
    {
        $this->authorizeUnit($request, $unit);

        $request->validate([
            'images'   => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120'], // 5 MB
        ], [
            'images.required' => 'أضف صورة واحدة على الأقل.',
            'images.*.image'  => 'الملف يجب أن يكون صورة صالحة.',
            'images.*.mimes'  => 'الصيغ المسموحة: jpg, png, webp, avif.',
            'images.*.max'    => 'حجم الصورة يجب ألا يتجاوز 5 ميجابايت.',
        ]);

        $files    = $request->file('images');
        $existing = $unit->images()->count();

        if ($existing + count($files) > self::MAX_IMAGES) {
            return response()->json([
                'message' => 'الحد الأقصى ' . self::MAX_IMAGES . ' صور لكل وحدة.',
            ], 422);
        }

        foreach ($files as $i => $file) {
            $path = $file->store("units/{$unit->id}", 'public');

            $unit->images()->create([
                'path' => $path,
                // First photo on an empty gallery becomes the main image.
                'is_main' => $existing === 0 && $i === 0,
            ]);
        }

        return response()->json(['data' => $this->gallery($unit)], 201);
    }

    /** DELETE /partner/units/{unit}/images/{image} */
    public function destroy(Request $request, Unit $unit, UnitImage $image): JsonResponse
    {
        $this->authorizeUnit($request, $unit);
        $this->authorizeImage($unit, $image);

        $wasMain = $image->is_main;
        $this->deleteFile($image->path);
        $image->delete();

        // Keep a main image if the deleted one was primary.
        if ($wasMain) {
            $unit->images()->orderBy('id')->first()?->update(['is_main' => true]);
        }

        return response()->json(['data' => $this->gallery($unit)]);
    }

    /** POST /partner/units/{unit}/images/{image}/main */
    public function setMain(Request $request, Unit $unit, UnitImage $image): JsonResponse
    {
        $this->authorizeUnit($request, $unit);
        $this->authorizeImage($unit, $image);

        $unit->images()->update(['is_main' => false]);
        $image->update(['is_main' => true]);

        return response()->json(['data' => $this->gallery($unit)]);
    }

    /** Ownership guard for the unit. */
    private function authorizeUnit(Request $request, Unit $unit): void
    {
        abort_if($unit->user_id !== $request->user()->id, 403, 'غير مصرح');
    }

    /** Ensure the image actually belongs to this unit (route bindings are global). */
    private function authorizeImage(Unit $unit, UnitImage $image): void
    {
        abort_if($image->unit_id !== $unit->id, 404, 'الصورة غير موجودة');
    }

    /** Remove the underlying file (skip legacy/absolute URLs). */
    private function deleteFile(string $path): void
    {
        if (! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** @return array<int, array<string, mixed>> main image first, then newest. */
    private function gallery(Unit $unit): array
    {
        return $unit->images()
            ->orderByDesc('is_main')
            ->orderByDesc('id')
            ->get()
            ->map(fn (UnitImage $img) => [
                'id'      => $img->id,
                'url'     => $img->url,
                'is_main' => $img->is_main,
            ])
            ->all();
    }
}
