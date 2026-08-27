<?php

declare(strict_types=1);

namespace App\Support\Units;

use App\Models\DashboardUpload;
use App\Models\Feature;
use App\Models\Unit;
use App\Support\Dashboard\Maps;
use App\Support\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What a listing IS — shared by the partner dashboard and the admin console.
 *
 * Both surfaces let someone build the same thing: a unit with photos, a permit,
 * amenities and a location, which then has to be complete enough to survive
 * review. The two consoles differ in their auth, their id formats and their
 * error envelopes — but not in any of that. Writing it twice would mean the day
 * one side gains a field or tightens a rule, the other quietly disagrees about
 * what a valid listing is, and the disagreement surfaces as a unit that passes
 * review on one path and not the other.
 *
 * So the envelope-specific parts stay in the controllers: this class returns
 * plain field-error maps and lets each caller render them its own way.
 */
final class UnitWriter
{
    /**
     * The platform's supported unit types.
     *
     * Three, not five. Migration 2026_07_01_000004 DELETED every unit of any
     * other type, so accepting `chalet` would create a row the rest of the
     * platform treats as unsupported — and that a re-run of that cleanup would
     * remove. {@see Unit::SUPPORTED_TYPES}, kept in sync deliberately.
     */
    public const TYPES = Unit::SUPPORTED_TYPES;

    /** Max photos per listing, matching the partner contract. */
    public const MAX_PHOTOS = 10;

    /**
     * Description bounds, in CHARACTERS.
     *
     * 500 was never a rule anyone chose — the console guessed it, asked for
     * confirmation, and did not get one. The storefront now renders the field
     * as line-based markup (headings, lists, notes), and a structured
     * description does not fit in 500: the example in the request exceeds it on
     * its own.
     *
     * Laravel sizes strings with mb_strlen, so this is 2000 characters and not
     * 2000 bytes — which would have meant ~666 Arabic characters, since Arabic
     * is three bytes each in UTF-8.
     *
     * The MINIMUM is a submit-time gate only: a draft may hold no description
     * at all, but a listing cannot go to review without one.
     */
    public const MIN_DESCRIPTION = 10;

    public const MAX_DESCRIPTION = 2000;

    /**
     * Validation rules for a unit body.
     *
     * @param  bool  $required  true → a complete listing; false → a draft, where
     *                          every field is optional and an ABSENT key means
     *                          "not supplied", never "blank it".
     * @return array<string, mixed>
     */
    public static function rules(bool $required = false): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            'name'                 => [$req, 'string', 'min:2', 'max:150'],
            'type'                 => [$req, 'in:'.implode(',', self::TYPES)],
            'pricePerNight'        => [$req, 'numeric', 'gt:0'],
            'cancellationPolicy'   => ['sometimes', 'in:flexible,moderate,strict'],
            'capacity'             => [$req, 'integer', 'min:1'],
            'bedrooms'             => ['sometimes', 'integer', 'min:0'],
            'beds'                 => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'bathrooms'            => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10'],
            // Any spelling — slug (`riyadh`), English (`Riyadh`) or Arabic
            // (`الرياض`) — is accepted and normalised in toColumns(). It must
            // still RESOLVE to a supported city: accepting free text here would
            // store a value no filter and no browse surface can ever match.
            'city'                 => [$req, 'string', 'max:100', self::cityRule()],
            'district'             => ['sometimes', 'nullable', 'string', 'max:150'],
            'sizeSqm'              => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'description'          => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_DESCRIPTION],
            'amenities'            => ['sometimes', 'nullable', 'array'],
            'amenities.*'          => ['string', 'in:'.implode(',', array_keys(Maps::AMENITIES))],
            'checkIn'              => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkOut'             => ['sometimes', 'nullable', 'date_format:H:i'],
            'lat'                  => ['sometimes', 'nullable', 'numeric'],
            'lng'                  => ['sometimes', 'nullable', 'numeric'],
            'address'              => ['sometimes', 'nullable', 'string', 'max:255'],
            'tourismLicenseNumber' => ['sometimes', 'nullable', 'string', 'max:50'],
            'tourismLicenseFileId' => ['sometimes', 'nullable', 'string'],
            'photoFileIds'         => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_PHOTOS],
            'photoFileIds.*'       => ['string'],
            'coverFileId'          => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** A city the platform actually serves, in any of the three spellings. */
    private static function cityRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) || \App\Support\City::toArabic($value) === null) {
                $fail('المدينة يجب أن تكون ضمن المدن المعتمدة');
            }
        };
    }

    /**
     * Map contract keys → DB columns, sanitising free text.
     *
     * Only keys actually present are mapped, so a partial body updates exactly
     * what it names.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toColumns(array $data): array
    {
        $map = [
            // Stored verbatim, like `description` below and for the same reason:
            // strip_tags() deletes from `<` through the next `>` rather than
            // escaping anything, so it silently truncates instead of protecting.
            'name'                 => fn ($v) => ['unit_name' => (string) $v],
            'type'                 => fn ($v) => ['unit_type' => $v],
            'pricePerNight'        => fn ($v) => ['price' => $v],
            // Preset slug → FK. Only affects FUTURE bookings: paid bookings
            // carry a frozen snapshot the engine reads exclusively (FR-036).
            'cancellationPolicy'   => fn ($v) => [
                'cancellation_policy_id' => \App\Models\CancellationPolicy::where('key', $v)->value('id'),
            ],
            'capacity'             => fn ($v) => ['capacity' => $v],
            'bedrooms'             => fn ($v) => ['bedrooms' => $v],
            'beds'                 => fn ($v) => ['beds' => $v],
            'bathrooms'            => fn ($v) => ['bathrooms' => $v],
            // `units.city` stores the Arabic name. The partner dashboard sends
            // slugs, the admin console sends English labels; both land here.
            'city'                 => fn ($v) => ['city' => \App\Support\City::toArabic((string) $v) ?? $v],
            'district'             => fn ($v) => ['district' => $v === null ? null : (string) $v],
            'sizeSqm'              => fn ($v) => ['area' => $v],
            // Stored verbatim. It used to go through strip_tags(), which is not
            // a sanitiser so much as a silent editor: `<` followed by anything
            // other than a space opens "tag mode" and everything up to the next
            // `>` is deleted. Since `>` opens a note line in the storefront's
            // markup, one `<=` in a description ate the line break and the note
            // marker with it — `"شروط <= ثلاثة\n> ملاحظة"` came out as
            // `"شروط  ملاحظة"`, corrupted in the column rather than the render.
            //
            // It bought nothing either: `<script>alert(1)</script>` survives it
            // as `alert(1)`. Safety here is escaping at render, which every
            // consumer already does — the JSON resources, the Vue storefront's
            // `{{ }}`, and the three Next.js apps, none of which use v-html or
            // dangerouslySetInnerHTML.
            'description'          => fn ($v) => ['description' => $v === null ? null : (string) $v],
            'checkIn'              => fn ($v) => ['checkin_time' => $v],
            'checkOut'             => fn ($v) => ['checkout_time' => $v],
            'lat'                  => fn ($v) => ['lat' => $v],
            'lng'                  => fn ($v) => ['lng' => $v],
            // The field a guest navigates by, and the one most likely to carry a
            // `<`: "<200م من المسجد" lost everything after the bracket.
            'address'              => fn ($v) => ['address' => $v === null ? null : (string) $v],
            'tourismLicenseNumber' => fn ($v) => ['tourism_permit_no' => $v],
            'tourismLicenseFileId' => fn ($v) => ['tourism_permit_file' => $v],
        ];

        $columns = [];
        foreach ($map as $key => $fn) {
            if (array_key_exists($key, $data)) {
                $columns = array_merge($columns, $fn($data[$key]));
            }
        }

        return $columns;
    }

    /**
     * Every referenced upload must be a STORED upload owned by this user, of the
     * kind matching where it is used.
     *
     * Checked up front so a bad fileId fails before any mutation — a listing
     * half-attached to files that do not exist is worse than a rejected write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string> field → Arabic message; empty means valid
     */
    public static function fileErrors(int $ownerId, array $data): array
    {
        $errors = [];

        if (! empty($data['tourismLicenseFileId'])
            && ! self::ownedUpload($ownerId, (string) $data['tourismLicenseFileId'], 'license_pdf')) {
            $errors['tourismLicenseFileId'] = 'ملف الرخصة غير موجود';
        }

        foreach ($data['photoFileIds'] ?? [] as $i => $fileId) {
            if (! self::ownedUpload($ownerId, (string) $fileId, 'unit_photo')) {
                $errors["photoFileIds.$i"] = 'الصورة غير موجودة';
            }
        }

        if (! empty($data['coverFileId'])
            && ! in_array($data['coverFileId'], $data['photoFileIds'] ?? [], true)) {
            $errors['coverFileId'] = 'صورة الغلاف يجب أن تكون ضمن الصور المرفوعة';
        }

        return $errors;
    }

    /**
     * Replace the gallery from the ordered photoFileIds.
     *
     * Absent key → gallery untouched. Present (even empty) → authoritative
     * replace of every photo the request is ABLE to address, so removing a photo
     * in the client actually removes it.
     *
     * Photos with no `file_id` are the exception, and are kept. They predate the
     * upload flow, so they have no id a client could put in `photoFileIds` — the
     * list can never contain them, and deleting them here would destroy a photo
     * in answer to a limitation of the request format rather than to anyone's
     * intent. There is no way to put such a file back.
     *
     * That means a client cannot empty a gallery it could not fully represent —
     * which is the safe direction to fail, and removes the need for every
     * console to carry its own guard against the same trap.
     *
     * @param  array<string, mixed>  $data
     */
    public static function syncPhotos(int $ownerId, Unit $unit, array $data): void
    {
        if (! array_key_exists('photoFileIds', $data)) {
            return;
        }

        // A present null means the same as an empty list — "replace the gallery
        // with nothing" — so the two array fields answer to the same spelling.
        $data['photoFileIds'] = $data['photoFileIds'] ?? [];

        $cover = $data['coverFileId'] ?? ($data['photoFileIds'][0] ?? null);

        DB::transaction(function () use ($ownerId, $unit, $data, $cover) {
            $unit->images()->whereNotNull('file_id')->where('file_id', '!=', '')->delete();

            $position = 0;

            foreach (array_values($data['photoFileIds']) as $fileId) {
                $upload = self::ownedUpload($ownerId, (string) $fileId, 'unit_photo');
                if (! $upload) {
                    continue; // already reported by fileErrors(); defensive
                }

                // Dimensions and derivative paths are denormalised from the
                // upload so the storefront never joins to read a gallery.
                $unit->images()->create([
                    'file_id'    => $upload->id,
                    'path'       => $upload->path,
                    'is_main'    => $fileId === $cover,
                    'sort_order' => $position++,
                    'width'      => $upload->width,
                    'height'     => $upload->height,
                    'variants'   => $upload->variants,
                ]);
            }

            self::reorderKeptPhotos($unit, $position, $position > 0);
        });
    }

    /**
     * Settle the photos that survived a sync because they could not be
     * addressed: give them positions after the ones the client ordered, and
     * make sure the unit still has exactly one cover.
     *
     * @param  int   $from      first free sort_order
     * @param  bool  $demote    true when the client supplied its own cover
     */
    private static function reorderKeptPhotos(Unit $unit, int $from, bool $demote): void
    {
        $kept = $unit->images()
            ->where(fn ($q) => $q->whereNull('file_id')->orWhere('file_id', ''))
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        foreach ($kept as $image) {
            $image->update([
                'sort_order' => $from++,
                'is_main'    => $demote ? false : (bool) $image->is_main,
            ]);
        }

        // A gallery with no cover renders no hero. Only reachable when the
        // client cleared every addressable photo and what remains never carried
        // the flag.
        if ($unit->images()->where('is_main', true)->doesntExist()) {
            $unit->images()->orderBy('sort_order')->orderBy('id')->first()?->update(['is_main' => true]);
        }
    }

    /**
     * Absent key → unchanged. Present → the list REPLACES what the unit has,
     * so `[]` (or `null`) clears every amenity.
     *
     * Takes the whole body rather than a pre-extracted value on purpose: with
     * `$data['amenities'] ?? null` the caller cannot tell "not supplied" from
     * "supplied as null", and an admin who unticked every box got a silent
     * no-op. Same shape as syncPhotos() so the two array fields behave alike.
     *
     * @param  array<string, mixed>  $data
     */
    public static function syncAmenities(Unit $unit, array $data): void
    {
        if (! array_key_exists('amenities', $data)) {
            return;
        }

        $ids = collect($data['amenities'] ?? [])
            ->map(fn ($k) => Maps::amenityToArabic($k))
            ->filter()
            ->map(fn ($name) => Feature::firstOrCreate(['name' => $name])->id);

        $unit->features()->sync($ids);
    }

    /**
     * Completeness at submit time — the gate between "a draft someone started"
     * and "something a reviewer can actually judge".
     *
     * @return array<string, string> field → Arabic message; empty means complete
     */
    public static function submitErrors(Unit $unit): array
    {
        $fields = [];

        if (Str::length((string) $unit->unit_name) < 2)                $fields['name'] = 'الاسم مطلوب';
        if (! in_array($unit->unit_type, self::TYPES, true))           $fields['type'] = 'نوع الوحدة غير صالح';
        if ((float) $unit->price <= 0)                                 $fields['pricePerNight'] = 'السعر يجب أن يكون أكبر من صفر';
        if ((int) $unit->capacity < 1)                                 $fields['capacity'] = 'السعة مطلوبة';
        if ((int) $unit->beds < 1)                                     $fields['beds'] = 'عدد السراير مطلوب';
        if ((int) $unit->bathrooms < 1)                                $fields['bathrooms'] = 'عدد دورات المياه مطلوب';
        if (! \App\Support\City::toArabic((string) $unit->city))       $fields['city'] = 'المدينة يجب أن تكون ضمن المدن المعتمدة';

        $descLen = Str::length((string) $unit->description);
        if ($descLen < self::MIN_DESCRIPTION || $descLen > self::MAX_DESCRIPTION) {
            $fields['description'] = 'الوصف يجب أن يكون بين '.self::MIN_DESCRIPTION.' و '.self::MAX_DESCRIPTION.' حرف';
        }
        if (blank($unit->address))                                     $fields['address'] = 'العنوان مطلوب';
        if ($unit->lat === null || $unit->lng === null || ! Maps::insideSaudi((float) $unit->lat, (float) $unit->lng)) {
            $fields['location'] = 'الموقع يجب أن يكون داخل حدود المملكة';
        }
        if (blank($unit->tourism_permit_no))                           $fields['tourismLicenseNumber'] = 'رقم رخصة السياحة مطلوب';
        if (blank($unit->tourism_permit_file))                         $fields['tourismLicenseFileId'] = 'ملف الرخصة مطلوب';
        // REAL photos, not rows: a placeholder row pointing at the shared
        // default image satisfied a bare count, which would let a listing reach
        // review with nothing to look at.
        if (self::realPhotoCount($unit) < 1)                           $fields['photos'] = 'أضف صورة واحدة على الأقل';

        return $fields;
    }

    /** Photos actually uploaded, excluding shared-default rows. */
    public static function realPhotoCount(Unit $unit): int
    {
        return $unit->images()
            ->whereNotNull('path')->where('path', '!=', '')
            ->where('path', '!=', Media::defaultImagePath())
            ->count();
    }

    public static function uniqueCode(string $prefix = 'MRN'): string
    {
        do {
            $code = $prefix.strtoupper(Str::random(5));
        } while (Unit::where('code', $code)->exists());

        return $code;
    }

    private static function ownedUpload(int $ownerId, string $fileId, string $kind): ?DashboardUpload
    {
        return DashboardUpload::whereKey($fileId)
            ->where('user_id', $ownerId)
            ->where('kind', $kind)
            ->where('status', 'stored')
            ->first();
    }
}
