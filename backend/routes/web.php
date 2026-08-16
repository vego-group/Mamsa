<?php

use Illuminate\Support\Facades\Route;

Route::get('/q', function () {
    return view('welcome');
});

/*
 * A MISSING file under /storage/*.
 *
 * Real files are served statically by the web server and never reach Laravel;
 * only paths with nothing behind them fall through to here. Without this route
 * they rendered Laravel's styled 403 page, which made "this file does not
 * exist" indistinguishable from "you are not allowed to see it" — and cost the
 * admin panel a wrong diagnosis: a mis-built URL was read as an authorization
 * failure and chased for a round.
 *
 * Storage carries no authorization at all, so 403 was never the truthful
 * answer. 404 is.
 */
Route::get('storage/{path}', fn () => abort(404))->where('path', '.*');
