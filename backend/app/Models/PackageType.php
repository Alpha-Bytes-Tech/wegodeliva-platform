<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of parcel customers can send (spec 03 §3.4): Document, Small box… Admins manage them.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $description
 * @property string|null $image_path
 * @property bool $requires_stop_verification
 * @property int $sort_order
 * @property bool $is_active
 */
class PackageType extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'description', 'image_path', 'requires_stop_verification', 'sort_order', 'is_active'];

    protected $hidden = ['id'];

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
        return ['requires_stop_verification' => 'boolean', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }
}
