<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies OpenID Connect ID tokens against the provider's published signing keys (JWKS):
 * signature, expiry, issuer and audience (our client IDs from config/wegodeliva.php).
 */
final class JwksSocialTokenVerifier implements SocialTokenVerifier
{
    private const PROVIDERS = [
        'google' => [
            'jwks' => 'https://www.googleapis.com/oauth2/v3/certs',
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
            'audience_config' => 'wegodeliva.social.google_client_ids',
        ],
        'apple' => [
            'jwks' => 'https://appleid.apple.com/auth/keys',
            'issuers' => ['https://appleid.apple.com'],
            'audience_config' => 'wegodeliva.social.apple_client_ids',
        ],
    ];

    public function verify(SocialProvider $provider, string $idToken): SocialIdentity
    {
        $settings = self::PROVIDERS[$provider->value];
        /** @var list<string> $audiences */
        $audiences = config($settings['audience_config'], []);
        if ($audiences === []) {
            Log::warning('Social sign-in attempted but no client IDs are configured.', ['provider' => $provider->value]);
            throw new ApiException('social_token_invalid', 401);
        }

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys($settings['jwks']), 'RS256'));
        } catch (Throwable $e) {
            Log::info('Social ID token rejected.', ['provider' => $provider->value, 'reason' => $e->getMessage()]);
            throw new ApiException('social_token_invalid', 401);
        }

        $issuerOk = in_array($claims['iss'] ?? null, $settings['issuers'], true);
        $audienceOk = in_array($claims['aud'] ?? null, $audiences, true);
        $subject = $claims['sub'] ?? null;
        if (! $issuerOk || ! $audienceOk || ! is_string($subject) || $subject === '') {
            throw new ApiException('social_token_invalid', 401);
        }

        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : null;
        $verified = $claims['email_verified'] ?? false;

        return new SocialIdentity(
            provider: $provider,
            subject: $subject,
            email: $email,
            // Apple sends "true" as a string.
            emailVerified: $verified === true || $verified === 'true',
            name: isset($claims['name']) && is_string($claims['name']) ? $claims['name'] : null,
        );
    }

    /** @return array<string, mixed> */
    private function keys(string $url): array
    {
        /** @var array<string, mixed> $keys */
        $keys = Cache::remember('jwks:'.sha1($url), now()->addHour(), function () use ($url): array {
            return Http::timeout(5)->retry(2, 200)->get($url)->throw()->json();
        });

        return $keys;
    }
}
