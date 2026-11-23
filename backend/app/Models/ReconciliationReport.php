<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The daily check of what WeGoDeliva owes its users against the gateway balances (spec 06 §6.4.1).
 *
 * @property int $id
 * @property Carbon $run_on
 * @property array<string, int> $ledger
 * @property array<string, int|null> $gateways
 * @property int $owed_kobo
 * @property int|null $held_kobo
 * @property int|null $shortfall_kobo
 * @property string $status ok | shortfall | incomplete
 */
class ReconciliationReport extends Model
{
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['run_on' => 'date', 'ledger' => 'array', 'gateways' => 'array', 'owed_kobo' => 'integer', 'held_kobo' => 'integer', 'shortfall_kobo' => 'integer'];
    }
}
