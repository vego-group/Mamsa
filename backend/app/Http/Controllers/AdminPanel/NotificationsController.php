<?php

declare(strict_types=1);

namespace App\Http\Controllers\AdminPanel;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The admin's own notification feed — BACKEND_SPEC §5.11. Backed by Laravel's
 * database notifications on the authenticated admin. Feed is capped, newest
 * first; unread-count returns a BARE JSON number (not an object).
 */
class NotificationsController extends Controller
{
    private const CAP = 50;

    /** GET /admin/notifications → NotificationItem[] (newest first, capped). */
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->limit(self::CAP)->get()
            ->map(fn (DatabaseNotification $n) => $this->item($n))->values();

        return response()->json($items);
    }

    /** GET /admin/notifications/unread-count → bare number, e.g. 5. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json((int) $request->user()->unreadNotifications()->count());
    }

    /** POST /admin/notifications/read-all → { ok: true }. */
    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->ok();
    }

    /** POST /admin/notifications/:id/read → { ok: true }. */
    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            $this->fail('NOT_FOUND', 'الإشعار غير موجود', 404);
        }

        $notification->markAsRead();

        return $this->ok();
    }

    /* ---------- mapping ---------- */

    /** @return array<string, mixed> NotificationItem — §6. */
    private function item(DatabaseNotification $n): array
    {
        $data = (array) $n->data;

        return [
            'id'       => (string) $n->id,
            'category' => $this->category($data, $n->type),
            'title'    => (string) ($data['title'] ?? ''),
            'body'     => (string) ($data['message'] ?? $data['body'] ?? ''),
            'at'       => $this->iso($n->created_at),
            'read'     => $n->read_at !== null,
            'entity'   => $this->entity($data),
        ];
    }

    /** Best-effort NotificationCategory from the payload / notification class. */
    private function category(array $data, ?string $type): string
    {
        $hint = strtolower((string) ($data['category'] ?? $data['type'] ?? class_basename((string) $type)));

        return match (true) {
            str_contains($hint, 'refund')                                                     => 'refund',
            str_contains($hint, 'cancel')                                                     => 'cancellation',
            str_contains($hint, 'unit') || str_contains($hint, 'approv') || str_contains($hint, 'review') => 'approval',
            str_contains($hint, 'book')                                                       => 'booking',
            str_contains($hint, 'partner') || str_contains($hint, 'application') || str_contains($hint, 'kyc') => 'partner',
            default                                                                            => 'system',
        };
    }

    /** Deep-link target from known id keys, or null. */
    private function entity(array $data): ?array
    {
        $map = [
            'approval'     => ['approval_id', 'unit_id'],
            'booking'      => ['booking_id'],
            'cancellation' => ['cancellation_id'],
            'partner'      => ['partner_id'],
        ];

        foreach ($map as $type => $keys) {
            foreach ($keys as $key) {
                if (! empty($data[$key])) {
                    return ['type' => $type, 'id' => (string) $data[$key]];
                }
            }
        }

        return null;
    }
}
