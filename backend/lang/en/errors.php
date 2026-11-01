<?php

// Client-facing error messages, keyed by error code (spec 14 §14.1). Keep them short and generic.
return [
    'validation_failed' => 'Some fields need your attention.',
    'unauthenticated' => 'Please sign in to continue.',
    'forbidden' => 'You are not allowed to do that.',
    'not_found' => 'We could not find that.',
    'method_not_allowed' => 'That action is not supported.',
    'conflict' => 'This changed while you were working. Please refresh and try again.',
    'bad_request' => 'The request could not be processed.',
    'rate_limited' => 'Too many attempts. Please wait a moment and try again.',
    'update_required' => 'Please update the app to continue.',
    'service_unavailable' => 'The service is temporarily unavailable.',
    'server_error' => 'Something went wrong on our side. Please try again.',

    'invalid_credentials' => 'The email or password is incorrect.',
    'email_not_verified' => 'Please confirm your email address with the code we send you.',
    'role_not_allowed' => 'This account cannot sign in to this app.',
    'account_suspended' => 'This account is suspended. Please contact support.',
    'otp_invalid' => 'That code is not correct.',
    'otp_expired' => 'That code has expired. Please request a new one.',
    'otp_attempts_exceeded' => 'Too many wrong codes. Please request a new one.',
    'otp_resend_too_soon' => 'Please wait before requesting another code.',
    'social_token_invalid' => 'We could not confirm your sign-in. Please try again.',
    'social_email_unverified' => 'Your account with this provider has no verified email address.',
    'phone_required' => 'Please add your phone number to finish signing up.',
    'account_exists' => 'An account with this email already exists. Sign in with your email to link it.',
];
