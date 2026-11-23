<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PaymentGateway;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\Payments\Gateways\Gateways;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Gateway webhooks (spec 06 §6.3): signature checked on the raw body, every event stored once
 * (a replay hits the unique key and is ignored), answered quickly and processed on the queue.
 */
class WebhookController extends Controller
{
    public function handle(Request $request, string $gateway, Gateways $gateways): JsonResponse
    {
        $gw = PaymentGateway::from($gateway);
        $client = $gateways->for($gw);

        if (! $client->hasValidSignature($request)) {
            Log::warning('Webhook with an invalid signature rejected', ['gateway' => $gw->value, 'ip' => $request->ip()]);
            throw new ApiException('invalid_signature', 401);
        }

        $payload = $request->json()->all();
        $notice = $client->parseWebhook($payload);
        if ($notice === null) {
            return response()->json(['received' => true]);
        }

        $stored = WebhookEvent::query()->insertOrIgnore([
            'gateway' => $gw->value,
            'event_type' => mb_substr($notice->eventType, 0, 60),
            'event_key' => mb_substr($notice->eventKey, 0, 120),
            'reference' => $notice->reference === null ? null : mb_substr($notice->reference, 0, 64),
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);

        if ($stored === 1) {
            $event = WebhookEvent::query()->where('gateway', $gw->value)->where('event_key', mb_substr($notice->eventKey, 0, 120))->firstOrFail();
            ProcessWebhookEvent::dispatch($event->id);
        }

        return response()->json(['received' => true]);
    }
}
