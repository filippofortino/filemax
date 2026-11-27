<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferRecipientsRequest;
use App\Models\Transfer;
use App\Notifications\TransferShared;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class TransferRecipientController
{
    /** Emails sent from the app's own domain, so each person has an hourly budget against misuse as a spam relay. */
    public const int EMAILS_PER_HOUR = 50;

    /** Emails the transfer to each address. Sending to an address again resends its email with the same personal link. */
    public function store(StoreTransferRecipientsRequest $request, Transfer $transfer): RedirectResponse
    {
        abort_unless($transfer->isAvailable(), 410);
        $user = $request->user() ?? abort(403);
        /** @var list<string> $emails */
        $emails = $request->validated('emails');
        $budget = 'transfer-mail:'.$user->id;

        if (count($emails) > RateLimiter::remaining($budget, self::EMAILS_PER_HOUR)) {
            throw ValidationException::withMessages(['emails' => 'You’ve sent a lot of emails in the last hour. Try again later, or copy the link instead.']);
        }

        RateLimiter::increment($budget, 3600, count($emails));

        foreach ($emails as $email) {
            $recipient = $transfer->recipients()->updateOrCreate(['email' => $email], ['sent_at' => now()]);
            Notification::route('mail', $email)->notify(new TransferShared($recipient->setRelation('transfer', $transfer)));
        }

        $sent = count($emails);
        Inertia::flash('toast', [
            'title' => $sent === 1 ? "Sent to {$emails[0]}" : "Sent to {$sent} people",
            'description' => 'You’ll see who opens it on the transfer page.',
        ]);

        return back();
    }
}
