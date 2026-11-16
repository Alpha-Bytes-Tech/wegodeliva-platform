<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterDeviceRequest;
use App\Http\Resources\DeviceResource;
use App\Models\Device;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** FCM push tokens (spec 14 §14.2 "Profile & devices"). */
class DeviceController extends Controller
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $app = $this->appFromToken($user);

        // A token belongs to one install; if another account used this phone before, move it.
        $device = Device::query()->firstOrNew(['fcm_token' => $request->string('fcm_token')->toString()]);
        $device->user()->associate($user);
        $device->fill([
            'app' => $app,
            'platform' => $request->string('platform')->toString(),
            'last_active_at' => now(),
        ])->save();

        return (new DeviceResource($device))->response()->setStatusCode($device->wasRecentlyCreated ? 201 : 200);
    }

    /** Another user's device answers 404, exactly like a missing one (spec 14 §14.1). */
    public function destroy(Request $request, string $uuid): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->devices()->where('uuid', $uuid)->firstOrFail()->delete();

        return response()->noContent();
    }

    private function appFromToken(User $user): ClientApp
    {
        foreach (ClientApp::cases() as $app) {
            if ($user->tokenCan($app->ability())) {
                return $app;
            }
        }

        abort(403);
    }
}
