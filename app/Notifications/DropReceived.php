<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Transfer;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

final class DropReceived extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    private const int LISTED_FILES = 5;

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
        $files = $transfer->files;
        $count = $files->count();
        $headline = ($transfer->sender_name ?? 'Someone').' sent you '.$count.' '.($count === 1 ? 'file' : 'files');

        $mail = new MailMessage()
            ->subject($headline)
            ->greeting($this->escape($headline))
            ->line('Through your Drop “'.$this->escape($drop->title ?? $transfer->displayTitle()).'”.');

        if ($transfer->message !== null) {
            $mail->line($this->escape($transfer->message));
        }

        foreach ($files->take(self::LISTED_FILES) as $file) {
            $mail->line($this->escape($file->original_name).' — '.$this->bytes($file->size));
        }

        if ($count > self::LISTED_FILES) {
            $more = $count - self::LISTED_FILES;
            $mail->line('and '.$more.' more '.($more === 1 ? 'file' : 'files'));
        }

        $mail->action('View files', route('received.show', $transfer))
            ->line('Only you can see these files. They’re kept until '.$this->date($transfer->expires_at, 'j M Y').'.');

        if ($drop?->isOpen() === true) {
            $mail->line('The Drop stays open until '.$this->date($drop->closes_at, 'j M Y, H:i').'. To stop new uploads sooner, close it in Drops.');
        }

        return $mail->line('You can turn these emails off in [Settings]('.route('account.settings.notifications').').');
    }

    /** Uploaders choose the name, message and file names, so their brackets must not become links or images in the owner's email. */
    private function escape(string $text): string
    {
        return (string) preg_replace('/([\\\\\[\]])/', '\\\\$1', $text);
    }

    /** Matches lib/format.ts bytes(): 1000-based units, at most two decimals. */
    private function bytes(int $bytes): string
    {
        $unit = $bytes > 0 ? min((int) floor(log($bytes, 1000)), 5) : 0;
        $value = mb_rtrim(mb_rtrim(number_format($bytes / 1000 ** $unit, 2, '.', ','), '0'), '.');

        return $value.' '.['B', 'KB', 'MB', 'GB', 'TB', 'PB'][$unit];
    }

    private function date(?CarbonInterface $date, string $format): string
    {
        return $date?->timezone(Config::string('filemax.timezone'))->format($format) ?? '';
    }
}
