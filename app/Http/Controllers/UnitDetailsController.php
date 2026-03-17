<?php

namespace App\Http\Controllers;

use App\Models\Unit;

class UnitDetailsController extends Controller
{
    public function show(Unit $unit)
    {
        $unit->load('images','owner');

        return view('pages.units.details', compact('unit'));
    }
}