<?php

namespace App\Mail;

use App\Enums\ClientApp;
use App\Enums\OtpPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time code email (ADR 0008). Says which app asked for it and never contains a link.
 */
class OtpCodeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly OtpPurpose $purpose,
        public readonly ClientApp $app,
        public readonly int $validMinutes,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} is your {$this->app->displayName()} code");
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.otp-code-text',
            with: [
                'appName' => $this->app->displayName(),
                'action' => match ($this->purpose) {
                    OtpPurpose::Login => 'sign in',
                    OtpPurpose::Verify => 'confirm your email address',
                    OtpPurpose::Reset => 'reset your password',
                },
            ],
        );
    }
}
