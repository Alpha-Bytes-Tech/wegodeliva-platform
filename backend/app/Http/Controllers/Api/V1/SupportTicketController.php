<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SupportTicketResource;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\AuditLogger;
use App\Services\Storage\StorageSigner;
use App\Support\UploadPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * "Report a problem" (spec 09 "Support"): customers open tickets about an order or their
 * account, with photos; support works them and the customer is told when the status changes.
 */
class SupportTicketController extends Controller
{
    public function __construct(private readonly StorageSigner $signer, private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', (array) config('wegodeliva.app.support_categories'))],
            'order_code' => ['nullable', 'string', 'max:12'],
            'message' => ['required', 'string', 'min:10', 'max:2000'],
            'photo_paths' => ['sometimes', 'array', 'max:'.config('wegodeliva.app.max_ticket_photos')],
            'photo_paths.*' => ['string', 'distinct', 'max:255', 'regex:'.UploadPaths::rule('support_photo', $user)],
        ]);

        $order = null;
        if (! empty($data['order_code'])) {
            $order = Order::query()->where('customer_id', $user->id)->where('code', strtoupper($data['order_code']))->first();
            if ($order === null) {
                throw new ApiException('validation_failed', 400, ['order_code' => ['Choose one of your orders.']]);
            }
        }

        $ticket = new SupportTicket;
        $ticket->forceFill([
            'user_id' => $user->id,
            'order_id' => $order?->id,
            'category' => $data['category'],
            'message' => $data['message'],
            'photo_paths' => array_values($data['photo_paths'] ?? []),
        ])->save();
        Log::info('Support ticket opened', ['ticket' => $ticket->uuid, 'category' => $ticket->category]);

        return $this->present($ticket->refresh())->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = SupportTicket::query()->where('user_id', $this->user($request)->id)->with('order')->orderByDesc('id')->cursorPaginate(20);

        return $this->collection($tickets->getCollection()->all(), $tickets);
    }

    public function show(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        if ($ticket->user_id !== $this->user($request)->id) {
            throw (new ModelNotFoundException)->setModel(SupportTicket::class);
        }

        return $this->present($ticket);
    }

    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('manage-orders');
        $status = $request->validate(['status' => ['nullable', 'in:open,in_progress,resolved,closed']])['status'] ?? null;
        $tickets = SupportTicket::query()->with('order', 'user')
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->orderByDesc('id')->cursorPaginate(25);

        return $this->collection($tickets->getCollection()->all(), $tickets, true);
    }

    public function adminUpdate(Request $request, SupportTicket $ticket): SupportTicketResource
    {
        Gate::authorize('manage-orders');
        $data = $request->validate([
            'status' => ['required', 'in:open,in_progress,resolved,closed'],
            'resolution' => ['required_if:status,resolved,closed', 'nullable', 'string', 'max:2000'],
        ]);
        $admin = $this->user($request);
        $before = $ticket->status;

        $done = in_array($data['status'], ['resolved', 'closed'], true);
        $ticket->forceFill([
            'status' => $data['status'],
            'resolution' => $data['resolution'] ?? $ticket->resolution,
            'handled_by' => $admin->id,
            'resolved_at' => $done ? ($ticket->resolved_at ?? now()) : null,
        ])->save();
        $this->audit->record($admin, 'support_ticket.updated', 'support_ticket', $ticket->uuid, ['status' => $before], ['status' => $ticket->status]);

        if ($before !== $ticket->status) {
            $ticket->user->notify(new AppNotification(ClientApp::Customer, 'customer.ticket_updated', [
                'status' => str_replace('_', ' ', $ticket->status),
            ], ['ticket' => $ticket->uuid]));
        }

        $resource = $this->present($ticket->load('order', 'user'));
        $resource->forAdmin = true;

        return $resource;
    }

    /**
     * @param  list<SupportTicket>  $tickets
     * @param  CursorPaginator<int, SupportTicket>  $page
     */
    private function collection(array $tickets, $page, bool $forAdmin = false): AnonymousResourceCollection
    {
        $urls = $this->signer->readUrls(array_merge([], ...array_map(fn (SupportTicket $t) => $t->photo_paths, $tickets)));

        return SupportTicketResource::collection($page->through(function (SupportTicket $t) use ($urls, $forAdmin) {
            $resource = new SupportTicketResource($t);
            $resource->photoUrls = $urls;
            $resource->forAdmin = $forAdmin;

            return $resource;
        }));
    }

    private function present(SupportTicket $ticket): SupportTicketResource
    {
        $resource = new SupportTicketResource($ticket->loadMissing('order'));
        $resource->photoUrls = $this->signer->readUrls($ticket->photo_paths);

        return $resource;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
