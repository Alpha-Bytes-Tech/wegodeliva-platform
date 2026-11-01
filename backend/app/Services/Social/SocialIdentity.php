<?php

namespace App\Services\Social;

use App\Enums\SocialProvider;

/** What we trust from a verified Google/Apple ID token. */
final readonly class SocialIdentity
{
    public function __construct(
        public SocialProvider $provider,
        public string $subject,
        public ?string $email,
        public bool $emailVerified,
        public ?string $name = null,
    ) {}
}
