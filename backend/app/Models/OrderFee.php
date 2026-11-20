<?php

namespace App\Models;

use App\Enums\Party;
use Illuminate\Database\Eloquent\Model;

/**
 * A service fee charged on an order (snapshot of the fee at placement).
 *
 * @property int $id
 * @property int $order_id
 * @property int|null $fee_id
 * @property string $name
 * @property int $amount_kobo
 * @property Party $beneficiary
 */
class OrderFee extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_kobo' => 'integer', 'beneficiary' => Party::class];
    }
}
