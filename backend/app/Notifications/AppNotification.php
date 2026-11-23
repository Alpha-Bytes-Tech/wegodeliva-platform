<?php

namespace App\Notifications;

use App\Enums\ClientApp;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One message to one person in one app (spec 08). The text comes from lang/en/notifications.php
 * by key with placeholders; the payload carries only ids and short text, never addresses or
 * phone numbers. Queued after the database transaction commits, so a rolled-back change never
 * notifies anyone.
 */
class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, string|int>  $params  placeholder values
     * @param  array<string, string>  $data  ids for the app to open the right screen
     * @param  list<string>  $mailLines  extra lines for the email version (e.g. a receipt)
     */
    public function __construct(
        public readonly ClientApp $app,
        public readonly string $key,
        public readonly array $params = [],
        public readonly array $data = [],
        public readonly bool $push = true,
        public readonly bool $mail = false,
        public readonly bool $urgent = false,
        public readonly ?string $channel = null,
        public readonly array $mailLines = [],
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return array_values(array_filter([
            'database',
            $this->push ? FcmChannel::class : null,
            $this->mail ? 'mail' : null,
        ]));
    }

    public function title(): string
    {
        return (string) __("notifications.{$this->key}.title", $this->params);
    }

    public function body(): string
    {
        return (string) __("notifications.{$this->key}.body", $this->params);
    }

    /** @return array<string, mixed> */
    public function toArray(User $notifiable): array
    {
        return ['app' => $this->app->value, 'key' => $this->key, 'title' => $this->title(), 'body' => $this->body(), 'data' => $this->data];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $message = (new MailMessage)->subject($this->title())->greeting('Hello '.(strtok($notifiable->name, ' ') ?: 'there').',')->line($this->body());
        foreach ($this->mailLines as $line) {
            $message->line($line);
        }

        return $message->salutation('WeGoDeliva');
    }
}
