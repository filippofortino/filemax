<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Transfer;
use App\Notifications\TransferExpiring;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Description('Email owners whose transfers expire within 24 hours')]
#[Signature('filemax:send-expiry-reminders')]
final class SendExpiryRemindersCommand extends Command
{
    public function handle(): int
    {
        $count = 0;

        Transfer::query()->with('user')
            ->where('status', 'ready')->whereNull('revoked_at')->whereNull('purged_at')->whereNull('expiry_reminder_sent_at')
            ->where('expires_in_days', '>', 1)->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDay())
            ->whereDoesntHave('user.settings', fn (Builder $settings) => $settings->where('notify_transfer_expiring', false))
            ->lazyById()->each(function (Transfer $transfer) use (&$count): void {
                $transfer->update(['expiry_reminder_sent_at' => now()]);
                $transfer->user->notify(new TransferExpiring($transfer));
                $count++;
            });

        $this->components->info("Sent {$count} expiry reminders.");

        return self::SUCCESS;
    }
}
