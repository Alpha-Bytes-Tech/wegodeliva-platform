<?php

namespace App\Notifications;

use App\Models\Device;
use App\Models\User;
use App\Services\Notifications\FcmClient;

/**
 * Sends push to each of the user's devices registered for the notification's app (never to
 * shared topics, spec 08 §8.2). Tokens FCM reports as invalid are removed.
 */
class FcmChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

    public function send(User $notifiable, AppNotification $notification): void
    {
        if (! $this->fcm->enabled()) {
            return;
        }

        $data = ['key' => $notification->key] + $notification->data;
        $devices = Device::query()->where('user_id', $notifiable->id)->where('app', $notification->app->value)->get();
        foreach ($devices as $device) {
            $result = $this->fcm->send($device->fcm_token, $notification->title(), $notification->body(), $data, $notification->urgent, $notification->channel);
            if ($result === FcmClient::INVALID_TOKEN) {
                $device->delete();
            }
        }
    }
}
