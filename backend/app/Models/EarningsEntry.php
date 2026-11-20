<?php

namespace App\Models;

use App\Enums\EarningsBucket;
use App\Enums\EarningsKind;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One earnings ledger entry. Append-only: the database refuses updates and deletes.
 *
 * @property int $id
 * @property string $uuid
 * @property int $earnings_account_id
 * @property EarningsKind $kind
 * @property EarningsBucket $bucket
 * @property int $amount_kobo signed
 * @property int $bucket_after_kobo
 * @property string $reference
 * @property int|null $order_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon $created_at
 */
class EarningsEntry extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'earnings_account_id', 'created_by'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => EarningsKind::class,
            'bucket' => EarningsBucket::class,
            'amount_kobo' => 'integer',
            'bucket_after_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
