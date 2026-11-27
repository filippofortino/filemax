<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Models\Transfer;
use Carbon\CarbonInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Config;

/** Shared pieces of the emails that describe a transfer's files. */
trait FormatsTransferMail
{
    private const int LISTED_FILES = 5;

    /** Adds the first few files with their sizes, then "and N more files". */
    private function listFiles(MailMessage $mail, Transfer $transfer): MailMessage
    {
        foreach ($transfer->files->take(self::LISTED_FILES) as $file) {
            $mail->line($this->escape(($file->folder !== null ? $file->folder.'/' : '').$file->original_name).' — '.$this->bytes($file->size));
        }

        $more = $transfer->files->count() - self::LISTED_FILES;
        if ($more > 0) {
            $mail->line('and '.$more.' more '.($more === 1 ? 'file' : 'files'));
        }

        return $mail;
    }

    /** People choose titles, messages and file names, so their brackets must not become links or images in an email. */
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
