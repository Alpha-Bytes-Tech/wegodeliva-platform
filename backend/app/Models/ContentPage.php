<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A legal or help page shown in the apps (terms, privacy, about, faq). Body is Markdown.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $body
 * @property bool $is_published
 * @property Carbon $updated_at
 */
class ContentPage extends Model
{
    protected $fillable = ['title', 'body', 'is_published'];

    protected $hidden = ['id'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }
}
