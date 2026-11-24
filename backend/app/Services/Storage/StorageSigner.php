<?php

namespace App\Services\Storage;

use App\Exceptions\ApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signed URLs for the private Supabase Storage bucket (CLAUDE.md §2, §4 "Supabase"). Apps
 * upload straight to a one-use signed URL and read through short-lived signed URLs; the
 * service-role key never leaves the server. Without storage settings, uploads answer 503 and
 * reads return null, so the rest of the API keeps working.
 */
class StorageSigner
{
    public function configured(): bool
    {
        return filled(config('wegodeliva.storage.url')) && filled(config('wegodeliva.storage.service_key'));
    }

    /** @return array{url: string, token: string} a URL the app PUTs the file to */
    public function uploadUrl(string $path): array
    {
        if (! $this->configured()) {
            throw new ApiException('uploads_unavailable', 503);
        }

        try {
            $response = $this->client()->post('/object/upload/sign/'.$this->bucket().'/'.$path);
        } catch (ConnectionException) {
            throw new ApiException('uploads_unavailable', 503);
        }
        if (! $response->successful() || ! is_string($response->json('url'))) {
            Log::warning('Storage refused a signed upload URL', ['status' => $response->status()]);
            throw new ApiException('uploads_unavailable', 503);
        }

        $url = $this->base().(string) $response->json('url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'token' => (string) ($query['token'] ?? '')];
    }

    /**
     * Short-lived read URLs for many paths in one call, cached for half their life.
     *
     * @param  list<string>  $paths
     * @return array<string, string|null> path => URL (null when it cannot be signed)
     */
    public function readUrls(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths)));
        if ($paths === []) {
            return [];
        }
        $empty = array_fill_keys($paths, null);
        if (! $this->configured()) {
            return $empty;
        }

        $ttl = (int) config('wegodeliva.storage.read_ttl_seconds');
        $urls = [];
        $missing = [];
        foreach ($paths as $path) {
            $cached = Cache::get($this->cacheKey($path));
            is_string($cached) ? $urls[$path] = $cached : $missing[] = $path;
        }
        if ($missing === []) {
            return $urls;
        }

        try {
            $response = $this->client()->post('/object/sign/'.$this->bucket(), ['expiresIn' => $ttl, 'paths' => $missing]);
        } catch (ConnectionException) {
            return $urls + $empty;
        }
        if (! $response->successful()) {
            Log::warning('Storage refused signed read URLs', ['status' => $response->status()]);

            return $urls + $empty;
        }

        foreach ((array) $response->json() as $item) {
            if (is_array($item) && is_string($item['path'] ?? null) && is_string($item['signedURL'] ?? null)) {
                $urls[$item['path']] = $this->base().$item['signedURL'];
                Cache::put($this->cacheKey($item['path']), $urls[$item['path']], intdiv($ttl, 2));
            }
        }

        return $urls + $empty;
    }

    public function readUrl(?string $path): ?string
    {
        return $path === null ? null : ($this->readUrls([$path])[$path] ?? null);
    }

    private function client(): PendingRequest
    {
        $key = (string) config('wegodeliva.storage.service_key');

        return Http::baseUrl($this->base())->withToken($key)->withHeaders(['apikey' => $key])->acceptJson()->timeout(10);
    }

    private function base(): string
    {
        return rtrim((string) config('wegodeliva.storage.url'), '/').'/storage/v1';
    }

    private function bucket(): string
    {
        return (string) config('wegodeliva.storage.bucket');
    }

    private function cacheKey(string $path): string
    {
        return 'storage-read:'.sha1($this->bucket().'/'.$path);
    }
}
