<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rating of a vendor, rider or product on a delivered order (spec 03 §3.8).
 *
 * @property int $id
 * @property string $uuid
 * @property int $order_id
 * @property int $author_user_id
 * @property string $subject_type vendor | rider | product
 * @property int $subject_id
 * @property int $rating
 * @property string|null $comment
 * @property bool $is_visible
 * @property Carbon $created_at
 * @property-read User|null $author
 */
class Review extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'order_id', 'author_user_id', 'subject_id'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_visible' => true];

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
        return ['rating' => 'integer', 'is_visible' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
