<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification center for Admin/SuperAdmin (FR-101, screen G-3).
 * Backed by Laravel database notifications on the authenticated user.
 */
class NotificationController extends Controller
{
    use ApiResponse;

    private const LIST_LIMIT = 30;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = $user->notifications()
            ->latest()
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn ($n) => array_merge($n->data, [
                'id'         => $n->id,
                'read'       => $n->read_at !== null,
                'created_at' => $n->created_at->toIso8601String(),
            ]));

        return $this->success([
            'items'        => $items,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return $this->success([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();
        $notification?->markAsRead();

        return $this->success([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->success(['unread_count' => 0]);
    }
}
