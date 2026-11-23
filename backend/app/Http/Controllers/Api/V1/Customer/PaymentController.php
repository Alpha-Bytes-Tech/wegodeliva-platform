<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\PaymentGateway;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Gateways\GatewayUnavailable;
use App\Services\Payments\PaymentProcessor;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Gateway checkout for orders and wallet top-ups (spec 06 §6.2, §6.4). "Verify" only asks the
 * server to check with the gateway; the app can never mark anything paid.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentProcessor $payments) {}

    public function payOrder(Request $request, string $code): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $order = Order::query()->where('customer_id', $customer->id)->where('code', strtoupper($code))->first()
            ?? throw (new ModelNotFoundException)->setModel(Order::class);

        return response()->json(['data' => $this->present($this->payments->startOrderPayment($order))], 201);
    }

    public function topUp(Request $request): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $key = trim((string) $request->header('Idempotency-Key'));
        $data = $request->validate([
            'amount_kobo' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'gateway' => ['required', 'in:paystack,flutterwave'],
        ]);
        if ($key === '' || strlen($key) > 100 || preg_match('/^[A-Za-z0-9_-]+$/', $key) !== 1) {
            throw new ApiException('validation_failed', 400, fields: ['idempotency_key' => ['Send an Idempotency-Key header (letters, digits, - or _, up to 100).']]);
        }

        [$topup, $payment, $created] = $this->payments->startTopUp($customer, (int) $data['amount_kobo'], PaymentGateway::from($data['gateway']), $key);

        return response()->json(['data' => ['topup_id' => $topup->uuid] + $this->present($payment)], $created ? 201 : 200);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        /** @var User $customer */
        $customer = $request->user();
        $payment = Payment::query()->where('user_id', $customer->id)->where('reference', $reference)->first()
            ?? throw (new ModelNotFoundException)->setModel(Payment::class);

        try {
            $payment = $this->payments->verify($payment);
        } catch (GatewayUnavailable) {
            throw new ApiException('gateway_unavailable', 503);
        }

        return response()->json(['data' => $this->present($payment->load('order', 'topup'))]);
    }

    /** @return array<string, mixed> */
    private function present(Payment $payment): array
    {
        return [
            'reference' => $payment->reference,
            'gateway' => $payment->gateway->value,
            'amount' => Money::of($payment->amount_kobo),
            'status' => $payment->status->value,
            'failure_reason' => $payment->failure_reason,
            'checkout_url' => $payment->status->isOpen() ? $payment->checkout_url : null,
            'order' => $payment->relationLoaded('order') && $payment->order !== null
                ? ['code' => $payment->order->code, 'status' => $payment->order->status->value, 'payment_status' => $payment->order->payment_status->value]
                : null,
            'topup' => $payment->relationLoaded('topup') && $payment->topup !== null
                ? ['id' => $payment->topup->uuid, 'status' => $payment->topup->status]
                : null,
        ];
    }
}
