<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\OrganizationInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class OrganizationInvite extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public OrganizationInvitation $invitation) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = $this->invitation->organization->name;
        $expires = $this->invitation->expires_at->timezone(config()->string('filemax.timezone'))->format('j M Y');

        return new MailMessage()
            ->subject(($this->invitation->inviter->name ?? 'Someone')." invited you to {$organization}")
            ->line("{$organization} uses Filemax to send and receive files. Join to send transfers as a member and to open the links shared with members only.")
            ->action("Join {$organization}", route('invitations.show', $this->invitation))
            ->line("This invite is for {$this->invitation->email} and works until {$expires}.")
            ->line('If you weren’t expecting it, no further action is required.');
    }
}
