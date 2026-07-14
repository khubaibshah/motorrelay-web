<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $notifications = $user->notifications()
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn ($notification) => $this->presentNotification($notification))
            ->values();

        return response()->json([
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $notification = $user->notifications()->where('id', $notificationId)->first();
        abort_unless($notification, 404);

        if (!$notification->read_at) {
            $notification->markAsRead();
        }

        return response()->json([
            'data' => $this->presentNotification($notification->fresh()),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Notifications marked as read.',
            'unread_count' => 0,
        ]);
    }

    public function destroy(Request $request, string $notificationId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $notification = $user->notifications()->where('id', $notificationId)->first();
        abort_unless($notification, 404);

        $notification->delete();

        return response()->json([
            'message' => 'Notification cleared.',
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);

        $user->notifications()->delete();

        return response()->json([
            'message' => 'Notifications cleared.',
            'unread_count' => 0,
        ]);
    }

    protected function presentNotification(object $notification): array
    {
        $data = (array) ($notification->data ?? []);

        return [
            'id' => $notification->id,
            'type' => $data['type'] ?? $notification->type,
            'title' => $data['title'] ?? $this->fallbackTitle($data, $notification->type),
            'body' => $data['body'] ?? $this->fallbackBody($data, $notification->type),
            'action_label' => $data['action_label'] ?? ($data['url'] ? 'Open' : null),
            'url' => $data['url'] ?? $this->fallbackUrl($data),
            'read_at' => optional($notification->read_at)->toIso8601String(),
            'created_at' => optional($notification->created_at)->toIso8601String(),
            'data' => $data,
        ];
    }

    protected function fallbackTitle(array $data, string $type): string
    {
        return match ($type) {
            'job.event' => 'Job update',
            'invoice.ready' => 'Invoice ready',
            'expense.reviewed' => 'Expense reviewed',
            default => 'Notification',
        };
    }

    protected function fallbackBody(array $data, string $type): string
    {
        if (!empty($data['job_title'])) {
            return sprintf('There was an update on %s.', $data['job_title']);
        }

        return match ($type) {
            'invoice.ready' => 'An invoice is ready for download.',
            'expense.reviewed' => 'Your expense has been reviewed.',
            default => 'You have a new notification.',
        };
    }

    protected function fallbackUrl(array $data): ?string
    {
        if (!empty($data['url'])) {
            return $data['url'];
        }

        if (!empty($data['job_id'])) {
            return url('/jobs/' . $data['job_id']);
        }

        if (!empty($data['invoice_id'])) {
            return url('/invoices/' . $data['invoice_id']);
        }

        return null;
    }
}
