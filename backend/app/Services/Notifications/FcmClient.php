<?php

namespace App\Services\Notifications;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 (spec 08: push only, per device token, no shared topics).
 * The service account comes from the environment (base64 JSON), never the repository; without
 * it push is switched off and messages still reach the inbox and email.
 */
class FcmClient
{
    public const SENT = 'sent';

    // The token is no longer valid: the caller deletes the device.
    public const INVALID_TOKEN = 'invalid_token';

    public const FAILED = 'failed';

    public function enabled(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  array<string, string>  $data  short ids and keys only (spec 08 §8.2)
     */
    public function send(string $token, string $title, string $body, array $data, bool $urgent = false, ?string $channel = null): string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return self::FAILED;
        }

        $message = [
            'token' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => $data,
            'android' => array_filter([
                'priority' => $urgent ? 'HIGH' : 'NORMAL',
                'notification' => $channel === null ? null : ['channel_id' => $channel, 'sound' => 'default'],
            ]),
            'apns' => ['headers' => ['apns-priority' => $urgent ? '10' : '5'], 'payload' => ['aps' => array_filter([
                'sound' => 'default',
                'interruption-level' => $urgent ? 'time-sensitive' : null,
            ])]],
        ];

        try {
            $response = Http::withToken($this->accessToken($credentials))
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", ['message' => $message]);
        } catch (ConnectionException|RuntimeException $e) {
            Log::warning('Push could not be sent', ['error' => $e->getMessage()]);

            return self::FAILED;
        }

        if ($response->successful()) {
            return self::SENT;
        }
        $code = (string) $response->json('error.details.0.errorCode', $response->json('error.status', ''));
        if ($response->status() === 404 || in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
            return self::INVALID_TOKEN;
        }
        Log::warning('Push rejected', ['status' => $response->status(), 'code' => $code]);

        return self::FAILED;
    }

    /** @return array{project_id: string, client_email: string, private_key: string}|null */
    private function credentials(): ?array
    {
        $encoded = (string) config('services.fcm.credentials');
        if ($encoded === '') {
            return null;
        }
        $json = json_decode((string) base64_decode($encoded, true), true);
        if (! is_array($json) || ! isset($json['project_id'], $json['client_email'], $json['private_key'])) {
            Log::error('FCM credentials are not a valid service account');

            return null;
        }

        return ['project_id' => (string) $json['project_id'], 'client_email' => (string) $json['client_email'], 'private_key' => (string) $json['private_key']];
    }

    /** @param  array{project_id: string, client_email: string, private_key: string}  $credentials */
    private function accessToken(array $credentials): string
    {
        return Cache::remember('fcm:access-token', now()->addMinutes(50), function () use ($credentials): string {
            $now = time();
            $segments = [
                self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                self::base64Url((string) json_encode([
                    'iss' => $credentials['client_email'],
                    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                    'aud' => 'https://oauth2.googleapis.com/token',
                    'iat' => $now,
                    'exp' => $now + 3600,
                ])),
            ];
            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('Could not sign the FCM token request.');
            }
            $segments[] = self::base64Url($signature);

            $token = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Google did not return an access token.');
            }

            return $token;
        });
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
