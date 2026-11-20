<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One opening period; several per weekday are allowed (split shifts). A period whose closing
 * time is not after its opening time runs past midnight (e.g. 18:00–02:00).
 *
 * @property int $id
 * @property int $vendor_id
 * @property int $weekday 0 = Sunday … 6 = Saturday, in the platform timezone
 * @property string $opens_at HH:MM:SS
 * @property string $closes_at HH:MM:SS
 */
class VendorHour extends Model
{
    protected $fillable = ['weekday', 'opens_at', 'closes_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
