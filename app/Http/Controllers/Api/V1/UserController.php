<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\UserResource;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use ApiResponse;

    public function profile(Request $request): JsonResponse
    {
        return $this->success(
            new UserResource($request->user()->load('roles'))
        );
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'unique:users,email,'.$user->id],
        ]);

        $user->update($validated);

        return $this->success(
            new UserResource($user->load('roles')),
            'تم تحديث الملف الشخصي'
        );
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = $request->user()
            ->bookings()
            ->with(['unit.images'])
            ->latest()
            ->paginate($request->integer('per_page', 10));

        return $this->success(
            BookingResource::collection($bookings)->response()->getData(true)
        );
    }
}
