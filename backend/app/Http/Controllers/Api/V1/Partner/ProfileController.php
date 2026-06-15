<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('partnerDetail'));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:100'],
            'email'       => ['sometimes', 'email', 'max:150'],
            'type'        => ['sometimes', 'in:individual,company'],
            'national_id' => ['nullable', 'string', 'max:20'],
            'cr_number'   => ['nullable', 'string', 'max:20'],
        ]);

        $request->user()->update(\Arr::only($data, ['name', 'email']));

        $partnerFields = \Arr::only($data, ['type', 'national_id', 'cr_number']);
        if ($partnerFields) {
            $request->user()->partnerDetail()->updateOrCreate(
                ['user_id' => $request->user()->id],
                $partnerFields
            );
        }

        return response()->json($request->user()->fresh()->load('partnerDetail'));
    }
}
