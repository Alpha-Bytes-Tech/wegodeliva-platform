<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Where an app's uploads go in private storage: {folder}/{user uuid}/{random}.{ext}. A path a
 * client sends back (an avatar, ticket photos) must sit in its own folder for that kind.
 */
final class UploadPaths
{
    private const EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public static function make(string $kind, User $user, string $contentType): string
    {
        $folder = (string) config("wegodeliva.storage.kinds.{$kind}.folder");

        return "{$folder}/{$user->uuid}/".Str::lower((string) Str::ulid()).'.'.self::EXTENSIONS[$contentType];
    }

    /** A validation regex for a path of this kind belonging to the user. */
    public static function rule(string $kind, User $user): string
    {
        $folder = preg_quote((string) config("wegodeliva.storage.kinds.{$kind}.folder"), '#');

        return '#^'.$folder.'/'.preg_quote($user->uuid, '#').'/[0-9a-z]{26}\.(jpg|png|webp|pdf)$#';
    }
}
