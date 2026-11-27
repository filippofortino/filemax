<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TransferFile;
use App\Models\TransferRecipient;
use App\Notifications\Concerns\FormatsTransferMail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** The owner emailed a transfer: the recipient gets its files listed and their own link to them. */
final class TransferShared extends Notification implements ShouldQueueAfterCommit
{
    use FormatsTransferMail;
    use Queueable;

    public function __construct(public TransferRecipient $recipient) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->recipient->transfer->isAvailable();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $transfer = $this->recipient->transfer;
        $sender = $transfer->user->settings->show_name_on_transfers ? $transfer->user : null;
        $organization = $transfer->organization->publicName();
        $count = $transfer->files->count();
        $files = $count.' '.($count === 1 ? 'file' : 'files');
        $headline = match (true) {
            $sender !== null => "{$sender->name} sent you {$files}",
            $organization !== null => "You’ve received {$files} from {$organization}",
            default => "You’ve received {$files}",
        };

        $mail = new MailMessage()
            ->subject($headline)
            ->greeting($this->escape($headline))
            ->line('“'.$this->escape($transfer->displayTitle()).'” · '.$this->bytes($transfer->files->reduce(fn (int $total, TransferFile $file): int => $total + $file->size, 0)));

        if ($transfer->message !== null) {
            $mail->line($this->escape($transfer->message));
        }

        $this->listFiles($mail, $transfer)
            ->action('Get your files', $this->recipient->url())
            ->line('The link works until '.$this->date($transfer->expires_at, 'j M Y, H:i').'.');

        $firstName = $sender !== null ? $this->escape(str($sender->name)->before(' ')->toString()) : null;
        if ($transfer->password_hash !== null) {
            $mail->line('It’s protected by a password. '.($firstName ?? 'The sender').' will give it to you separately.');
        }

        if ($transfer->visibility === 'members') {
            $mail->line('Only members of '.$this->escape($transfer->organization->name).' can open it. Sign in with your '.$this->escape($transfer->organization->name).' account to download.');
        }

        if ($sender !== null) {
            $mail->replyTo($sender->email, $sender->name)->line("Reply to this email to answer {$firstName} directly.");
        }

        return $mail;
    }
}
