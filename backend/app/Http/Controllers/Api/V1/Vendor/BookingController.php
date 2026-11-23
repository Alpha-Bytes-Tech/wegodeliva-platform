<?php

namespace App\Http\Controllers\Api\V1\Vendor;

use App\Enums\OrderStatus;
use App\Http\Resources\OrderOpsResource;
use App\Models\Order;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Orders\Bookings;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

/**
 * A provider's booking actions (spec 10 "Booking details"): confirm a request, start with the
 * customer's code, mark done. Declining uses the order reject endpoint. Owner and staff.
 */
class BookingController extends VendorController
{
    public function __construct(private readonly Bookings $bookings) {}

    public function confirm(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);

        return $this->present($this->bookings->confirm($this->booking($vendor, $code), $this->user($request)));
    }

    public function start(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $data = $request->validate(['start_code' => ['required', 'string', 'size:4']]);

        return $this->present($this->bookings->start($this->booking($vendor, $code), $this->user($request), $data['start_code']));
    }

    public function complete(Request $request, Vendor $vendor, string $code): OrderOpsResource
    {
        $this->member($request, $vendor);
        $data = $request->validate(['collected_via' => ['nullable', 'in:cash,transfer']]);

        return $this->present($this->bookings->complete($this->booking($vendor, $code), $this->user($request), $data['collected_via'] ?? null));
    }

    private function booking(Vendor $vendor, string $code): Order
    {
        return Order::query()->where('vendor_id', $vendor->id)->where('code', strtoupper($code))
            ->where('status', '!=', OrderStatus::AwaitingPayment->value)
            ->first() ?? throw (new ModelNotFoundException)->setModel(Order::class);
    }

    private function present(Order $order): OrderOpsResource
    {
        return OrderOpsResource::for($order->load('vendor', 'customer', 'rider', 'items'), 'vendor');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
