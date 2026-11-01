<?php

namespace App\Models;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $channel
 * @property string $destination
 * @property OtpPurpose $purpose
 * @property ClientApp $app
 * @property string $code_hash
 * @property Carbon $expires_at
 * @property int $attempts
 * @property Carbon|null $consumed_at
 * @property string|null $ip
 * @property Carbon $created_at
 */
class OtpChallenge extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purpose' => OtpPurpose::class,
            'app' => ClientApp::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @param  Builder<OtpChallenge>  $query
     * @return Builder<OtpChallenge>
     */
    public function scopeOpenFor(Builder $query, string $destination, OtpPurpose $purpose): Builder
    {
        return $query->where('destination', $destination)
            ->where('purpose', $purpose->value)
            ->whereNull('consumed_at');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
