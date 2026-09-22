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
 * Items (review document of 22/09, section 3.1 and 3.2):
 *   1. unit #39 ("وحدة اختبار الدفع") and everything that depends on it.
 *   2. the two license_pdf uploads attached to no unit (16/09, 19/09).
 *   3. every orphan upload of the test accounts #14, #15, #20 — computed,
 *      never hard-coded: an upload nothing references.
 *   4. access and refresh tokens of deleted users, and of #14 / #15 / #16.
 *   5. the two suspended test accounts #15 and #16.
 *   6. #35 / #37: NO MERGE. The file all of them carry is byte-identical to
 *      the document read as a PRIVATE one-unit permit, so #35 is classified
 *      private_hospitality through the licence writer and #37 is hidden
 *      (status = unavailable), neither deleted, approval_status untouched.
 *
 * NOT touched, by explicit instruction: #14 (it is the created_by of the Mamsa
 * units), #19, #20, #23, and #21 / #22 with their upload.
 *
 * Everything is one transaction; a dry run rolls it back and prints what it
 * would have removed. Row counts per table are printed either way.
 */

$EXECUTE = false;

$UNIT_ID = 39;
$ORPHAN_UPLOADS = ['file_01m2mp05902j81zap1vnpq0ef8', 'file_01m2v7jnvaevethykfykr00c5z'];

$counts = [];
$count = function (string $table, int $n) use (&$counts) { $counts[$table] = ($counts[$table] ?? 0) + $n; };

// Uploads removed, tallied by the account that owned them — asked for in the
// go-ahead, and only knowable while the rows still exist.
$uploadsByOwner = [];
$tallyUpload = function (int $ownerId) use (&$uploadsByOwner) { $uploadsByOwner[$ownerId] = ($uploadsByOwner[$ownerId] ?? 0) + 1; };

$public = Storage::disk('public');

/**
 * Is this upload referenced by ANYTHING?
 *
 * DocumentStorage::referencedAnywhere() is not the test to use here: it covers
 * the document columns only (tourism_permit_file, ownership_doc_file, the
 * partner-detail files), because that is all `forget()` is ever handed. Photos
 * are referenced from unit_images instead, so asking it about a unit_photo
 * returns false for a photo that is on a live listing — the first run of this
 * script listed the photos of #34, #35 and #37 for deletion.
 */
$referenced = function (\App\Models\DashboardUpload $up): bool {
    if (\App\Support\Documents\DocumentStorage::referencedAnywhere($up->id)) {
        return true;
    }

    $byId = DB::table('unit_images')->where('file_id', $up->id)->exists();
    $byPath = filled($up->path) && DB::table('unit_images')->where('path', $up->path)->exists();

    return $byId || $byPath;
};

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

    // --- items 2 + 3: orphan uploads ---
    //
    // Computed, not listed: an upload is an orphan when NOTHING references it.
    // A hard-coded list would delete a file that had been attached to something
    // in between the survey and the run.
    $orphanOwners = [14, 15, 20, 19];
    $candidates = \App\Models\DashboardUpload::whereIn('user_id', $orphanOwners)
        ->orWhereIn('id', $ORPHAN_UPLOADS)->get();

    foreach ($candidates as $up) {
        if ($referenced($up)) {
            continue; // still in use — the permit files and photos of #34/#35/#37
        }

        foreach (array_values((array) ($up->variants ?? [])) as $variant) {
            if (blank($variant)) { continue; }
            if ($public->exists($variant)) {
                $files[] = $variant;
                if ($EXECUTE) { $public->delete($variant); }
            }
        }

        // The document itself may be on either disk: sensitive kinds were moved
        // into the vault by the documents work, so a public-disk existence check
        // alone reports "nothing to delete" for every licence PDF and leaves the
        // bytes behind while the row disappears. locate() answers for both.
        if (filled($up->path)) {
            [$disk] = \App\Support\Documents\DocumentStorage::locate($up->path);

            if ($disk !== null) {
                $files[] = $up->path;
                if ($EXECUTE) { \App\Support\Documents\DocumentStorage::delete($up->path); }
            }
        }

        $tallyUpload((int) $up->user_id);
        $count('dashboard_uploads', (int) $up->delete());
    }

    // --- item 4: tokens of deleted users, and of the test accounts ---
    $liveUserIds = DB::table('users')->pluck('id')->all();
    $count('personal_access_tokens', DB::table('personal_access_tokens')
        ->where(fn ($q) => $q->whereNotIn('tokenable_id', $liveUserIds)->orWhereIn('tokenable_id', [14, 15, 16]))
        ->delete());
    $count('refresh_tokens', DB::table('refresh_tokens')
        ->where(fn ($q) => $q->whereNotIn('user_id', $liveUserIds)->orWhereIn('user_id', [14, 15, 16]))
        ->delete());

    // --- item 5: the two suspended test accounts ---
    //
    // #14 STAYS: it is the created_by of every Mamsa-owned unit, and the
    // reassignment log points at it. #15 and #16 own nothing.
    foreach ([15, 16] as $userId) {
        $u = \App\Models\User::find($userId);
        if (! $u) { continue; }
        if ($u->is_active) { throw new RuntimeException("user #$userId is ACTIVE — refusing"); }
        if ($u->units()->count() || $u->bookings()->count()) { throw new RuntimeException("user #$userId owns units or bookings — refusing"); }

        $count('partner_wallets', DB::table('partner_wallets')->where('partner_user_id', $userId)->delete());
        $count('wallet_transactions', DB::table('wallet_transactions')->where('user_id', $userId)->delete());
        $count('partner_details', DB::table('partner_details')->where('user_id', $userId)->delete());
        $count('notifications', DB::table('notifications')->where('notifiable_id', $userId)->where('notifiable_type', 'like', '%User')->delete());
        $count('model_has_roles', DB::table('model_has_roles')->where('model_id', $userId)->where('model_type', 'like', '%User')->delete());

        // Whatever of this account's files the orphan sweep left is still here
        // because something pointed at it — its own partner detail, deleted one
        // line ago. Deleting the rows without the bytes is how a national-ID
        // scan outlives the account it belonged to.
        foreach (\App\Models\DashboardUpload::where('user_id', $userId)->get() as $up) {
            foreach (array_values((array) ($up->variants ?? [])) as $variant) {
                if (blank($variant)) { continue; }
                if ($public->exists($variant)) {
                    $files[] = $variant;
                    if ($EXECUTE) { $public->delete($variant); }
                }
            }

            if (filled($up->path)) {
                [$disk] = \App\Support\Documents\DocumentStorage::locate($up->path);
                if ($disk !== null) {
                    $files[] = $up->path;
                    if ($EXECUTE) { \App\Support\Documents\DocumentStorage::delete($up->path); }
                }
            }

            $tallyUpload((int) $up->user_id);
            $count('dashboard_uploads', (int) $up->delete());
        }

        $count('users', DB::table('users')->where('id', $userId)->delete());
    }

    // --- item 6: #35 stays alone and private; #37 is hidden, not deleted ---
    //
    // Through the licence writer, so the group-wide rule and the CHECK both
    // hold. approval_status is deliberately NOT touched: this is a data
    // correction by an operator, not an edit by the partner, so it must not
    // send a live listing back for review.
    $u35 = \App\Models\Unit::findOrFail(35);
    $before35 = [$u35->license_type, $u35->approval_status, $u35->status];
    \App\Support\Units\UnitLicense::applyToGroup($u35, [
        'license_type' => \App\Support\Units\UnitLicense::PRIVATE_HOSPITALITY,
        'licensed_units_count' => null,
    ]);
    $u35->refresh();
    echo "  #35 license_type {$before35[0]} → {$u35->license_type}; approval {$before35[1]} → {$u35->approval_status}; status {$before35[2]} → {$u35->status}".PHP_EOL;

    $u37 = \App\Models\Unit::findOrFail(37);
    $before37 = [$u37->approval_status, $u37->status];
    $u37->update(['status' => 'unavailable']);
    $u37->refresh();
    echo "  #37 approval {$before37[0]} → {$u37->approval_status}; status {$before37[1]} → {$u37->status}".PHP_EOL;

    echo PHP_EOL.($EXECUTE ? 'EXECUTED' : 'DRY RUN — rolled back').PHP_EOL;
    foreach ($counts as $t => $n) { printf("  %-22s %d\n", $t, $n); }

    echo '  uploads by owner: ';
    ksort($uploadsByOwner);
    foreach ($uploadsByOwner as $owner => $n) { echo "#$owner=$n  "; }
    echo '(total '.array_sum($uploadsByOwner).')'.PHP_EOL;
    echo "  files ".($EXECUTE ? 'deleted' : 'that would be deleted').": ".count($files).PHP_EOL;
    foreach ($files as $f) { echo "    $f".PHP_EOL; }

    $EXECUTE ? DB::commit() : DB::rollBack();
} catch (\Throwable $e) {
    DB::rollBack();
    echo 'ABORTED: '.$e->getMessage().PHP_EOL;
}
