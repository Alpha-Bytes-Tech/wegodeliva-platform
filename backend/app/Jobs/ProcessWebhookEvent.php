<?php

namespace App\Jobs;

use App\Enums\PaymentGateway;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payments\Gateways\Gateways;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Acts on a stored webhook (spec 06 §6.3). A charge event only triggers our own verification
 * with the gateway; the webhook payload is never trusted for status or amount. Transfer events
 * (payouts) are recorded now and handled with milestone 2.7.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly int $eventId) {}

    public function handle(Gateways $gateways, PaymentProcessor $payments): void
    {
        $event = WebhookEvent::query()->findOrFail($this->eventId);
        if ($event->processed_at !== null) {
            return;
        }

        $notice = $gateways->for(PaymentGateway::from($event->gateway))->parseWebhook($event->payload);
        if ($notice !== null && $notice->isCharge && $notice->reference !== null) {
            $payment = Payment::query()->where('gateway', $event->gateway)->where('reference', $notice->reference)->first();
            if ($payment !== null) {
                $payments->verify($payment);
            }
        }

        $event->forceFill(['processed_at' => now(), 'processing_error' => null])->save();
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::query()->whereKey($this->eventId)->update(['processing_error' => mb_substr($e->getMessage(), 0, 255)]);
    }
}
