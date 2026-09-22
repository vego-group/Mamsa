<?php

/**
 * Production test-data cleanup — decisions document of 22/09/2026, section 4.
 *
 * Run through tinker on the server:
 *   php artisan tinker docs/ops/scripts/2026-09-22-production-cleanup.php < /dev/null
 *
 * DRY RUN by default. Set $EXECUTE = true only after the full DB backup named
 * in the runbook exists and the owner's go for THIS run has been given.
 *
 * Items:
 *   1. unit #39 ("وحدة اختبار الدفع") and everything that depends on it.
 *   2. the two license_pdf uploads attached to no unit (16/09, 19/09).
 *   3. #35 / #37 — NOT here. See the report: the permit file both carry is
 *      byte-identical to the document the frontend read as a PRIVATE permit
 *      for one unit, so writing `tourist_facility` on it needs a human answer
 *      first.
 *
 * Everything is one transaction; a dry run rolls it back and prints what it
 * would have removed. Row counts per table are printed either way.
 */

$EXECUTE = false;

$UNIT_ID = 39;
$ORPHAN_UPLOADS = ['file_01m2mp05902j81zap1vnpq0ef8', 'file_01m2v7jnvaevethykfykr00c5z'];

$counts = [];
$count = function (string $table, int $n) use (&$counts) { $counts[$table] = ($counts[$table] ?? 0) + $n; };

DB::beginTransaction();

try {
    $unit = \App\Models\Unit::findOrFail($UNIT_ID);
    if (! $unit->mamsa_owned || ! str_contains((string) $unit->unit_name, 'اختبار')) {
        throw new RuntimeException("unit #$UNIT_ID does not look like the test unit — refusing");
    }

    $bookingIds = \App\Models\Booking::where('unit_id', $UNIT_ID)->pluck('id')->all();
    echo "unit #$UNIT_ID «{$unit->unit_name}» bookings: ".implode(',', $bookingIds).PHP_EOL;

    // --- dependents of the bookings ---
    $count('payments', DB::table('payments')->whereIn('booking_id', $bookingIds)->delete());
    $count('wallet_transactions', DB::table('wallet_transactions')->whereIn('booking_id', $bookingIds)->delete());
    $count('refunds', DB::table('refunds')->whereIn('booking_id', $bookingIds)->delete());
    $count('reviews', DB::table('reviews')->whereIn('booking_id', $bookingIds)->delete());
    $count('notifications', DB::table('notifications')->where(function ($q) use ($UNIT_ID, $bookingIds) {
        $q->where('data', 'like', '%"unit_id":'.$UNIT_ID.'%');
        foreach ($bookingIds as $b) { $q->orWhere('data', 'like', '%"booking_id":'.$b.'%'); }
    })->delete());
    $count('bookings', DB::table('bookings')->whereIn('id', $bookingIds)->delete());

    // --- dependents of the unit ---
    // Photo files: the original and its derivatives, from the image rows
    // themselves (the test unit's photos have no dashboard_uploads rows).
    // Deleted only if no OTHER unit's image row points at the same path.
    $photoPaths = [];
    foreach (\App\Models\UnitImage::where('unit_id', $UNIT_ID)->get() as $img) {
        if (filled($img->path)) { $photoPaths[] = $img->path; }
        foreach ((array) ($img->variants ?? []) as $variant) { if (filled($variant)) { $photoPaths[] = $variant; } }
    }
    $imageFileIds = DB::table('unit_images')->where('unit_id', $UNIT_ID)->whereNotNull('file_id')->pluck('file_id')->all();
    $count('unit_images', DB::table('unit_images')->where('unit_id', $UNIT_ID)->delete());
    $count('unit_features', DB::table('unit_features')->where('unit_id', $UNIT_ID)->delete());
    $count('unit_blocked_dates', DB::table('unit_blocked_dates')->where('unit_id', $UNIT_ID)->delete());
    $count('favorites', DB::table('favorites')->where('unit_id', $UNIT_ID)->delete());
    if (Schema::hasTable('permits')) { // phase 1 may or may not be on production yet
        $count('permits', DB::table('permits')->where('scope_type', 'unit')->where('scope_id', (string) $UNIT_ID)->delete());
    }
    $count('units', DB::table('units')->where('id', $UNIT_ID)->delete());

    // --- files: the unit's photos, now referenced by nothing ---
    $files = [];
    $public = Storage::disk('public');
    foreach (array_unique($photoPaths) as $path) {
        $stillUsed = DB::table('unit_images')->where('path', $path)->orWhere('variants', 'like', '%'.str_replace('/', '\\/', $path).'%')->exists();
        if (! $stillUsed && $public->exists($path)) {
            $files[] = $path;
            if ($EXECUTE) { $public->delete($path); }
        }
    }
    foreach ($imageFileIds as $fid) {
        $up = \App\Models\DashboardUpload::find($fid);
        if ($up && ! \App\Support\Documents\DocumentStorage::referencedAnywhere($fid)) {
            $files[] = $up->path;
            if ($EXECUTE) { \App\Support\Documents\DocumentStorage::delete($up->path); }
            $count('dashboard_uploads', (int) $up->delete());
        }
    }

    // --- item 2: the two orphan permit uploads ---
    foreach ($ORPHAN_UPLOADS as $fid) {
        if (\App\Support\Documents\DocumentStorage::referencedAnywhere($fid)) {
            throw new RuntimeException("$fid is referenced — refusing to delete");
        }
        $up = \App\Models\DashboardUpload::find($fid);
        if ($up) {
            $files[] = $up->path;
            if ($EXECUTE) { \App\Support\Documents\DocumentStorage::delete($up->path); }
            $count('dashboard_uploads', (int) $up->delete());
        }
    }

    echo PHP_EOL.($EXECUTE ? 'EXECUTED' : 'DRY RUN — rolled back').PHP_EOL;
    foreach ($counts as $t => $n) { printf("  %-22s %d\n", $t, $n); }
    echo "  files ".($EXECUTE ? 'deleted' : 'that would be deleted').": ".count($files).PHP_EOL;
    foreach ($files as $f) { echo "    $f".PHP_EOL; }

    $EXECUTE ? DB::commit() : DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    echo 'ABORTED: '.$e->getMessage().PHP_EOL;
}
