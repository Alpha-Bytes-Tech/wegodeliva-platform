<?php

namespace App\Models;

use App\Enums\EarningsOwner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vendor's or rider's bank account for payouts (spec 06 §6.7). The name comes from the bank.
 *
 * @property int $id
 * @property string $uuid
 * @property EarningsOwner $owner_type
 * @property int $owner_id
 * @property string $bank_code
 * @property string $bank_name
 * @property string $account_number
 * @property string $account_name
 * @property string $status verified | needs_review
 * @property bool $is_default
 * @property string|null $recipient_code
 */
class PayoutAccount extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'owner_id', 'recipient_code'];

    /** @var array<string, mixed> */
    protected $attributes = ['is_default' => false];

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
        return ['owner_type' => EarningsOwner::class, 'is_default' => 'boolean'];
    }

    /** The account number with all but the last four digits hidden. */
    public function maskedNumber(): string
    {
        return '******'.substr($this->account_number, -4);
    }
}
