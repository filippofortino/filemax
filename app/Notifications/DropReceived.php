<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transfer;
use App\Notifications\Concerns\FormatsTransferMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class DropReceived extends Notification implements ShouldQueueAfterCommit
{
    use FormatsTransferMail;
    use Queueable;

    public function __construct(public Transfer $transfer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->transfer->isAvailable() && $this->transfer->user->settings->notify_drop_received;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transfer = $this->transfer;
        $drop = $transfer->drop;
        $count = $transfer->files->count();
        $headline = ($transfer->sender_name ?? 'Someone').' sent you '.$count.' '.($count === 1 ? 'file' : 'files');

        $mail = new MailMessage()
            ->subject($headline)
            ->greeting($this->escape($headline))
            ->line('Through your Drop “'.$this->escape($drop->title ?? $transfer->displayTitle()).'”.');

        $this->listFiles($this->quote($mail, $transfer->message), $transfer)
            ->action('View files', route('received.show', $transfer))
            ->line('Only you can see these files. They’re kept until '.$this->date($transfer->expires_at, 'j M Y').'.');

        if ($drop?->isOpen() === true) {
            $mail->line('The Drop stays open until '.$this->date($drop->closes_at, 'j M Y, H:i').'. To stop new uploads sooner, close it in Drops.');
        }

        return $mail->line('You can turn these emails off in [Settings]('.route('account.settings.notifications').').');
    }
}
