<?php

namespace App\Notifications;

use App\Models\SocialConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SocialTokenExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private SocialConnection $socialConnection;

    public function __construct(SocialConnection $socialConnection)
    {
        $this->socialConnection = $socialConnection;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reconnect your {$this->socialConnection->platform} account")
            ->line("The {$this->socialConnection->platform} connection for **{$this->socialConnection->platform_page_name}** needs to be reauthorised.")
            ->line('The access token could not be refreshed automatically.')
            ->action('Reconnect in Settings', config('app.frontend_url', '/') . '/settings')
            ->line('If you do not reconnect, comment moderation for this platform will stop working.');
    }
}
