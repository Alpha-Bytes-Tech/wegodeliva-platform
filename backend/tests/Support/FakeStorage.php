<?php

namespace Tests\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A Supabase Storage stand-in: signs uploads and reads predictably, or fails on request. */
final class FakeStorage
{
    public const URL = 'https://storage.test';

    public const BROKEN = 'https://broken-storage.test';

    public const DOWN = 'https://down-storage.test';

    public static function fake(): void
    {
        config(['wegodeliva.storage.url' => self::URL, 'wegodeliva.storage.service_key' => 'service-key', 'wegodeliva.storage.bucket' => 'uploads']);
        Http::fake([
            self::URL.'/storage/v1/object/sign/uploads' => fn (Request $r) => Http::response(array_map(
                fn (string $p) => ['path' => $p, 'signedURL' => "/object/sign/uploads/{$p}?token=read", 'error' => null],
                $r['paths'],
            )),
            self::URL.'/storage/v1/object/upload/sign/*' => fn (Request $r) => Http::response(['url' => str_replace(self::URL.'/storage/v1', '', $r->url()).'?token=up-123']),
            self::BROKEN.'/*' => Http::response(['error' => 'Bucket not found'], 404),
            self::DOWN.'/*' => fn () => throw new ConnectionException('down'),
        ]);
    }

    /** Points the app at a storage that refuses (BROKEN) or cannot be reached (DOWN). */
    public static function use(string $url): void
    {
        config(['wegodeliva.storage.url' => $url]);
    }

    public static function readUrl(string $path): string
    {
        return self::URL."/storage/v1/object/sign/uploads/{$path}?token=read";
    }
}
