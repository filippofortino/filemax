<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TransferDownloaded extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public Transfer $transfer) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->transfer->displayTitle();

        return new MailMessage()
            ->subject("“{$title}” was downloaded")
            ->line("Someone downloaded your transfer “{$title}” for the first time.")
            ->action('View transfer', route('transfers.show', $this->transfer))
            ->line('We only email you about the first download of each transfer. You can turn these emails off in [Settings]('.route('account.settings').').');
    }
}
