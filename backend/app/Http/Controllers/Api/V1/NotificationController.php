<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app inbox (spec 08): each app shows the user's notifications for that app only, newest
 * first, and can mark some or all as read.
 */
class NotificationController extends Controller
{
    public function index(Request $request, string $app): JsonResponse
    {
        $validated = $request->validate(['unread' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);
        $page = $this->inbox($request, $app)
            ->when((bool) ($validated['unread'] ?? false), fn (Builder $q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->cursorPaginate((int) ($validated['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'key' => $n->data['key'] ?? null,
                'title' => $n->data['title'] ?? null,
                'body' => $n->data['body'] ?? null,
                'data' => $n->data['data'] ?? [],
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'unread' => $this->inbox($request, $app)->whereNull('read_at')->count(),
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    /** Marks the given notifications, or all of this app's, as read. */
    public function markRead(Request $request, string $app): JsonResponse
    {
        $ids = $request->validate(['ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['uuid']])['ids'] ?? null;
        $updated = $this->inbox($request, $app)
            ->whereNull('read_at')
            ->when($ids !== null, fn (Builder $q) => $q->whereIn('id', $ids))
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['marked' => $updated]]);
    }

    /** @return Builder<DatabaseNotification> */
    private function inbox(Request $request, string $app): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->id)
            ->where('data->app', $app);
    }
}
