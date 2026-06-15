<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        return response()->json($request->user()->load('partnerDetail'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'  => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:150'],
        ]);

        $request->user()->update($data);

        return response()->json($request->user()->fresh());
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with(['unit.images', 'payment', 'review'])
            ->latest()
            ->paginate(10);

        return response()->json(BookingResource::collection($bookings));
    }
}
