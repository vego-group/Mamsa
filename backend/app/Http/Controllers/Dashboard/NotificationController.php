<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Partner notification center (contract §8). Read + read-all only — no delete.
 * Grouping/time-labels are frontend presentation derived from createdAt.
 */
class NotificationController extends DashboardController
{
    public function index(Request $request): JsonResponse
    {
        [$page, $limit] = $this->pageArgs($request);

        $paginator = $request->user()->notifications()->paginate(perPage: $limit, page: $page);

        return $this->paginated($paginator, fn (DatabaseNotification $n) => self::shape($n));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $request->user()->notifications()->whereKey($id)->first()?->markAsRead();

        return $this->ok();
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->ok();
    }

    private static function shape(DatabaseNotification $n): array
    {
        $d = $n->data;

        return [
            'id'        => $n->id,
            'type'      => $d['type'] ?? 'general',
            'title'     => $d['title'] ?? '',
            // Notifications predating the dashboard use `message`/`action_url`.
            'body'      => $d['body'] ?? $d['message'] ?? '',
            'read'      => $n->read_at !== null,
            'createdAt' => $n->created_at->toIso8601ZuluString(),
            'href'      => $d['href'] ?? $d['action_url'] ?? null,
        ];
    }
}
