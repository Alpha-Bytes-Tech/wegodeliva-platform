<?php

namespace App\Models;

use App\Enums\EarningsOwner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A vendor or rider document for approval (spec 03 §3.3 vendor_documents, §3.7 rider_documents).
 *
 * @property int $id
 * @property string $uuid
 * @property EarningsOwner $owner_type
 * @property int $owner_id
 * @property string $kind
 * @property string|null $file_path
 * @property string $status requested | submitted | approved | rejected
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_note
 * @property Carbon $created_at
 */
class Document extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'owner_id', 'reviewed_by'];

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['owner_type' => EarningsOwner::class, 'reviewed_at' => 'datetime'];
    }
}
