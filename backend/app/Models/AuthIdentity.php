<?php

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property SocialProvider $provider
 * @property string $provider_user_id
 * @property string|null $email
 * @property-read User $user
 */
class AuthIdentity extends Model
{
    protected $fillable = ['provider', 'provider_user_id', 'email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['provider' => SocialProvider::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
