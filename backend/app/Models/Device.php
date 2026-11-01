<?php

namespace App\Models;

use App\Enums\ClientApp;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property ClientApp $app
 * @property string $platform
 * @property string $fcm_token
 * @property Carbon|null $last_active_at
 */
class Device extends Model
{
    use HasUuids;

    protected $fillable = ['app', 'platform', 'fcm_token', 'last_active_at'];

    protected $hidden = ['id', 'user_id'];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['app' => ClientApp::class, 'last_active_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
