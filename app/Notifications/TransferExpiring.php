<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transfer;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class TransferExpiring extends Notification implements ShouldQueue
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
        $remaining = $this->transfer->expires_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE);

        return new MailMessage()
            ->subject("“{$title}” expires in {$remaining}")
            ->line("Your transfer “{$title}” expires in {$remaining}. After that, the download link stops working.")
            ->action('Extend transfer', route('transfers.show', $this->transfer))
            ->line('Extending it keeps it available for 7 more days. You can turn these emails off in [Settings]('.route('account.settings').').');
    }
}
