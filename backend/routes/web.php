<?php

use Illuminate\Support\Facades\Route;

Route::get('/q', function () {
    return view('welcome');
});
