<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;
use App\Exceptions\ApiException;

/**
 * Verifies a Google/Apple ID token server-side. Bound to JwksSocialTokenVerifier in
 * production; tests bind a fake.
 */
interface SocialTokenVerifier
{
    /** @throws ApiException with code social_token_invalid */
    public function verify(SocialProvider $provider, string $idToken): SocialIdentity;
}
