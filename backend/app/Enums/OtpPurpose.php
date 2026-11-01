<?php

namespace App\Enums;

enum OtpPurpose: string
{
    /** Sign in, or finish sign-up (verifies the email address). */
    case Login = 'login';
    /** Verify a new email address (change-email, LATER). */
    case Verify = 'verify';
    /** Reset a forgotten password. */
    case Reset = 'reset';
}
