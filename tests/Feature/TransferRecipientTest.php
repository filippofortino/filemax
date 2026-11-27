<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Transfer;
use App\Models\TransferActivity;
use App\Models\TransferFile;
use App\Models\TransferRecipient;
use App\Models\User;
use App\Notifications\TransferShared;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->withoutVite();
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    Notification::fake();
});

/** A public transfer sent from an organization, so its members can be told apart from other Filemax users. */
function organizationTransfer(array $attributes = []): Transfer
{
    return Transfer::factory()->membersOnly()->create(['visibility' => 'public', ...$attributes]);
}

it('emails each address once with its own link and resends to an address already sent to', function (): void {
    $transfer = Transfer::factory()->create();

    $this->actingAs($transfer->user)->from(route('transfers.show', $transfer))
        ->post(route('transfers.recipients.store', $transfer), ['emails' => " Anna@Studio.it, luca@example.com;\nanna@studio.it "])
        ->assertRedirect(route('transfers.show', $transfer))->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.title', 'Sent to 2 people');

    $anna = $transfer->recipients()->where('email', 'anna@studio.it')->sole();
    Notification::assertSentOnDemandTimes(TransferShared::class, 2);
    Notification::assertSentOnDemand(TransferShared::class, fn (TransferShared $notification, array $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['mail'] === 'anna@studio.it'
        && $notification->toMail($notifiable)->actionUrl === route('shared.show', ['transfer' => $transfer->token, 'recipient' => $anna->id]));

    $this->travel(1)->hour();
    $this->post(route('transfers.recipients.store', $transfer), ['emails' => ['anna@studio.it']])->assertSessionHasNoErrors();

    expect($transfer->recipients()->count())->toBe(2)
        ->and($anna->refresh()->sent_at->equalTo(now()))->toBeTrue();
    Notification::assertSentOnDemandTimes(TransferShared::class, 3);
});

it('rejects bad addresses, more than ten at a time, and people who may not share the transfer', function (): void {
    $transfer = Transfer::factory()->create();
    $url = route('transfers.recipients.store', $transfer);

    $this->actingAs($transfer->user)->post($url, ['emails' => 'not-an-email'])->assertSessionHasErrors('emails.0');
    $this->post($url, ['emails' => ''])->assertSessionHasErrors('emails');
    $this->post($url, ['emails' => collect(range(1, 11))->map(fn (int $i): string => "p{$i}@example.com")->implode(',')])->assertSessionHasErrors('emails');
    $this->flushSession()->actingAs(User::factory()->create())->post($url, ['emails' => 'a@example.com'])->assertForbidden();

    $expired = Transfer::factory()->expired()->create();
    $this->flushSession()->actingAs($expired->user)->post(route('transfers.recipients.store', $expired), ['emails' => 'a@example.com'])->assertGone();

    $left = Transfer::factory()->membersOnly()->create();
    $left->organization->users()->detach($left->user_id);
    $this->flushSession()->actingAs($left->user)->post(route('transfers.recipients.store', $left), ['emails' => 'a@example.com'])->assertForbidden();

    Notification::assertNothingSent();
});

it('lets a members-only transfer be emailed to anyone', function (): void {
    $transfer = Transfer::factory()->membersOnly()->create();

    $this->actingAs($transfer->user)->post(route('transfers.recipients.store', $transfer), ['emails' => 'outsider@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(TransferShared::class, function (TransferShared $notification, array $channels, AnonymousNotifiable $notifiable) use ($transfer): bool {
        $mail = $notification->toMail($notifiable);

        return str_contains(implode("\n", $mail->outroLines), "Only members of {$transfer->organization->name} can open it.");
    });
});

it('caps how many emails one person can send in an hour', function (): void {
    $transfer = Transfer::factory()->create();
    $this->actingAs($transfer->user);
    $tenAddresses = fn (int $batch): string => collect(range(1, 10))->map(fn (int $i): string => "b{$batch}p{$i}@example.com")->implode(',');

    foreach (range(1, 5) as $batch) {
        $this->post(route('transfers.recipients.store', $transfer), ['emails' => $tenAddresses($batch)])->assertSessionHasNoErrors();
    }

    $this->post(route('transfers.recipients.store', $transfer), ['emails' => 'one-more@example.com'])->assertSessionHasErrors('emails');
    expect(RateLimiter::attempts('transfer-mail:'.$transfer->user_id))->toBe(50);
    $this->travel(61)->minutes();
    $this->post(route('transfers.recipients.store', $transfer), ['emails' => 'one-more@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(TransferShared::class, 51);
});

it('writes the email from what the transfer allows the recipient to see', function (): void {
    $transfer = organizationTransfer(['title' => 'Spot [final](https://evil.test)', 'message' => 'Ecco ![x](y)', 'password_hash' => Hash::make('Transfer123')]);
    $transfer->user->update(['name' => 'Filippo Fortino']);
    foreach (range(1, 6) as $position) {
        TransferFile::factory()->for($transfer)->create(['original_name' => $position === 1 ? 'file1 <i>|</i>.jpg' : "file{$position}.jpg", 'size' => 1000, 'position' => $position]);
    }

    $recipient = TransferRecipient::factory()->for($transfer)->create();
    $mail = new TransferShared($recipient)->toMail(new AnonymousNotifiable);
    $lines = implode("\n", $mail->introLines).implode("\n", $mail->outroLines);

    expect($mail->subject)->toBe('Filippo Fortino sent you 6 files')
        ->and($mail->replyTo)->toBe([[$transfer->user->email, 'Filippo Fortino']])
        ->and($lines)->toContain('Spot \[final\](https://evil.test)', '> Ecco !\[x\](y)', '| file1 &lt;i&gt;\|&lt;/i&gt;.jpg | 1 KB |', '| file5.jpg | 1 KB |', 'and 1 more file', 'protected by a password. Filippo will give it')
        ->and($lines)->not->toContain('file6.jpg', 'Transfer123');

    $transfer->user->settings()->create(['show_name_on_transfers' => false]);
    $mail = new TransferShared($recipient->fresh())->toMail(new AnonymousNotifiable);

    expect($mail->subject)->toBe("You’ve received 6 files from {$transfer->organization->name}")
        ->and($mail->replyTo)->toBe([])
        ->and(implode("\n", $mail->outroLines))->toContain('The sender will give it')->not->toContain('Filippo');

    $transfer->update(['revoked_at' => now()]);
    expect(new TransferShared($recipient->fresh())->shouldSend(new AnonymousNotifiable, 'mail'))->toBeFalse();
});

it('remembers the recipient from their link and redirects to the clean link', function (): void {
    $transfer = Transfer::factory()->create();
    $recipient = TransferRecipient::factory()->for($transfer)->create();
    $clean = route('shared.show', $transfer->token);

    $this->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => $recipient->id]))
        ->assertRedirect($clean)->assertSessionHas('transfer_recipients.'.$transfer->id, $recipient->id);
    $this->get($clean)->assertOk();

    expect(TransferActivity::query()->sole())->kind->toBe('opened')->transfer_recipient_id->toBe($recipient->id)->user_id->toBeNull();
    $this->get($clean)->assertOk();
    expect(TransferActivity::query()->count())->toBeOne();
});

it('counts one open for a recipient who signs in as the member with their address, and one per other person', function (): void {
    $transfer = organizationTransfer();
    $elena = User::factory()->create(['email' => 'elena@mediamax.it']);
    $marco = User::factory()->create();
    $transfer->organization->users()->attach([$elena->id, $marco->id]);
    $recipient = TransferRecipient::factory()->for($transfer)->create(['email' => 'elena@mediamax.it']);

    $this->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => $recipient->id]))->assertRedirect();
    $this->get(route('shared.show', $transfer->token))->assertOk();
    $this->actingAs($elena)->get(route('shared.show', $transfer->token))->assertOk();
    expect(TransferActivity::query()->where('kind', 'opened')->count())->toBeOne();

    $this->flushSession()->actingAs($marco)->withSession(['transfer_recipients.'.$transfer->id => $recipient->id])
        ->get(route('shared.show', $transfer->token))->assertOk();
    expect(TransferActivity::query()->where('kind', 'opened')->count())->toBe(2);
});

it('ignores a recipient id that is unknown, belongs to another transfer, or is opened by the owner', function (): void {
    $transfer = Transfer::factory()->create();
    $other = TransferRecipient::factory()->create();
    $own = TransferRecipient::factory()->for($transfer)->create();

    $this->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => $other->id]))->assertRedirect()->assertSessionMissing('transfer_recipients.'.$transfer->id);
    $this->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => 'nope']))->assertRedirect()->assertSessionMissing('transfer_recipients.'.$transfer->id);
    $this->actingAs($transfer->user)->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => $own->id]))->assertRedirect()->assertSessionMissing('transfer_recipients.'.$transfer->id);
    $this->get(route('shared.show', $transfer->token))->assertOk();

    expect(TransferActivity::query()->count())->toBe(0);
});

it('keeps crediting the recipient through the password page', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $file = TransferFile::factory()->for($transfer)->create();
    $recipient = TransferRecipient::factory()->for($transfer)->create();

    $this->get(route('shared.show', ['transfer' => $transfer->token, 'recipient' => $recipient->id]))->assertRedirect();
    $this->get(route('shared.show', $transfer->token))->assertInertia(fn (Assert $page): Assert => $page->component('shared/password', false));
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertRedirect();
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk();

    expect(TransferActivity::query()->where('kind', 'downloaded')->sole())->transfer_recipient_id->toBe($recipient->id)->transfer_file_id->toBe($file->id);
});

it('credits downloads and previews to members and recipients only', function (string $action): void {
    Queue::fake();
    $transfer = organizationTransfer();
    $file = TransferFile::factory()->for($transfer)->create(['folder' => 'Photos', 'preview_kind' => 'image', 'preview_status' => 'ready']);
    $act = fn () => match ($action) {
        'file' => $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk(),
        'folder' => $this->postJson(route('shared.download', $transfer->token), ['folder' => 'Photos'])->assertOk(),
        'all' => $this->postJson(route('shared.download', $transfer->token))->assertOk(),
        'preview' => $this->postJson(route('shared.files.preview', [$transfer->token, $file]))->assertOk(),
    };

    $act();
    $this->actingAs($transfer->user);
    $act();
    $this->flushSession()->actingAs(User::factory()->create());
    $act();
    expect(TransferActivity::query()->count())->toBe(0);

    $member = User::factory()->create();
    $transfer->organization->users()->attach($member);
    $this->flushSession()->actingAs($member);
    $act();
    $recipient = TransferRecipient::factory()->for($transfer)->create();
    auth()->logout();
    $this->flushSession()->withSession(['transfer_recipients.'.$transfer->id => $recipient->id]);
    $act();

    $activities = TransferActivity::query()->oldest()->orderBy('user_id', 'desc')->get();
    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('kind')->unique()->all())->toBe([$action === 'preview' ? 'previewed' : 'downloaded'])
        ->and($activities->pluck('user_id')->filter()->values()->all())->toBe([$member->id])
        ->and($activities->pluck('transfer_recipient_id')->filter()->values()->all())->toBe([$recipient->id])
        ->and($activities->first()->transfer_file_id)->toBe(in_array($action, ['file', 'preview'], true) ? $file->id : null)
        ->and($activities->first()->folder)->toBe($action === 'folder' ? 'Photos' : null);
})->with(['file', 'folder', 'all', 'preview']);

it('lists people on the transfer page, one per address, including those who have not opened it', function (): void {
    $transfer = organizationTransfer();
    $file = TransferFile::factory()->for($transfer)->create();
    $member = User::factory()->create(['name' => 'Elena Ferrari', 'email' => 'elena@mediamax.it']);
    $transfer->organization->users()->attach($member);
    $elenaByEmail = TransferRecipient::factory()->for($transfer)->create(['email' => 'elena@mediamax.it']);
    $anna = TransferRecipient::factory()->for($transfer)->create(['email' => 'anna@studio.it']);
    TransferRecipient::factory()->for($transfer)->create(['email' => 'waiting@example.com', 'sent_at' => now()->subDay()]);
    $transfer->activities()->createMany([
        ['user_id' => $member->id, 'transfer_recipient_id' => $elenaByEmail->id, 'kind' => 'opened', 'created_at' => now()->subHours(3)],
        ['user_id' => $member->id, 'kind' => 'downloaded', 'transfer_file_id' => $file->id, 'created_at' => now()->subHours(2)],
        ['user_id' => $member->id, 'kind' => 'downloaded', 'transfer_file_id' => $file->id, 'created_at' => now()->subHour()],
        ['transfer_recipient_id' => $anna->id, 'kind' => 'previewed', 'transfer_file_id' => $file->id, 'created_at' => now()->subHours(5)],
    ]);

    $this->actingAs($transfer->user)->get(route('transfers.show', $transfer))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('transfers/show', false)
        ->has('people', 3)
        ->where('people.0.email', 'elena@mediamax.it')
        ->where('people.0.name', 'Elena Ferrari')
        ->where('people.0.sent_at', $elenaByEmail->sent_at->toIso8601String())
        ->where('people.0.last_active_at', now()->subHour()->toIso8601String())
        ->where('people.0.activity.0', ['kind' => 'downloaded', 'file_id' => $file->id, 'folder' => null, 'times' => 2, 'last_at' => now()->subHour()->toIso8601String()])
        ->where('people.0.activity.1.kind', 'opened')
        ->where('people.1.email', 'anna@studio.it')
        ->where('people.1.name', null)
        ->where('people.2.email', 'waiting@example.com')
        ->where('people.2.last_active_at', null)
        ->where('people.2.activity', []));
});

it('does not name other Filemax users who open a public link', function (): void {
    $transfer = organizationTransfer();
    $stranger = User::factory()->create();
    Organization::factory()->create()->users()->attach($stranger);

    $this->actingAs($stranger)->get(route('shared.show', $transfer->token))->assertOk();
    $this->flushSession()->actingAs($transfer->user)->get(route('transfers.show', $transfer))->assertInertia(fn (Assert $page): Assert => $page->has('people', 0));
});
