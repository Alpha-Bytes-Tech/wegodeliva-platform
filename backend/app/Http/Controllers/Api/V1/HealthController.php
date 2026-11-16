<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/** Liveness and readiness probes (spec 14 §14.2 "Health"). No internals in responses. */
class HealthController extends Controller
{
    public function live(): JsonResponse
    {
        return response()->json(['data' => ['status' => 'ok']]);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('select 1')),
            'cache' => $this->check('cache', function (): void {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 'ok', 10);
                $ok = Cache::pull($key) === 'ok';
                if (! $ok) {
                    throw new \RuntimeException('Cache round-trip failed.');
                }
            }),
            'queue' => $this->check('queue', fn () => Queue::size()),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()->json(
            ['data' => ['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks]],
            $healthy ? 200 : 503,
        );
    }

    private function check(string $name, callable $probe): string
    {
        try {
            $probe();

            return 'ok';
        } catch (Throwable $e) {
            Log::error("Readiness check failed: {$name}", ['exception' => $e]);

            return 'fail';
        }
    }
}
