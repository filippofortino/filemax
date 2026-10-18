<?php

declare(strict_types=1);

use App\Jobs\PurgeTransfer;
use App\Models\Drop;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Notifications\DropReceived;
use App\Notifications\TransferExpiring;
use App\Services\TransferStorage;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function receivedTransfer(User $owner, array $attributes = []): Transfer
{
    $transfer = Transfer::factory()->for($owner)->received()->create($attributes);
    TransferFile::factory()->create(['transfer_id' => $transfer->id, 'original_name' => 'Stand_001.jpg', 'size' => 14_200_000]);

    return $transfer;
}

it('lists only the owner’s received files, newest arrival first, apart from sent transfers', function (): void {
    $owner = User::factory()->create();
    $older = receivedTransfer($owner, ['sender_name' => 'Studio Vela', 'received_at' => now()->subDay()]);
    $newer = receivedTransfer($owner, ['sender_name' => 'Giulia Neri', 'received_at' => now()->subHour(), 'created_at' => now()->subDays(2)]);
    $opened = receivedTransfer($owner, ['sender_name' => 'Andrea Conti', 'received_at' => now()->subDays(2), 'first_opened_at' => now()]);
    $gone = receivedTransfer($owner, ['sender_name' => 'Davide Greco', 'received_at' => now()->subDays(40), 'expires_at' => now()->subDays(10)]);
    Transfer::factory()->for($owner)->received()->uploading()->create();
    receivedTransfer(User::factory()->create());
    $sent = Transfer::factory()->for($owner)->create();

    $this->actingAs($owner)->get(route('received.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('received/index', false)
        ->where('received.data', fn ($received): bool => collect($received)->pluck('id')->all() === [$newer->id, $older->id, $opened->id, $gone->id])
        ->where('totals', ['total' => 4, 'new' => 2])
        ->where('received.data.0.is_new', true)
        ->where('received.data.0.sender_name', 'Giulia Neri')
        ->where('received.data.0.total_size', 14_200_000)
        ->where('received.data.2.is_new', false)
        ->where('received.data.3.available', false)
        ->where('received.data.3.token', null)
        ->where('received.data.3.gone_at', $gone->expires_at?->toIso8601String()));

    $this->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfers.data', fn ($transfers): bool => collect($transfers)->pluck('id')->all() === [$sent->id])
        ->where('totals.total', 1)
        ->where('receivedNew', 2));
});

it('serves Received as a tab of My transfers without clashing with the transfer pages', function (): void {
    $owner = User::factory()->create();
    $received = receivedTransfer($owner);
    $sent = Transfer::factory()->for($owner)->create();
    $this->actingAs($owner);

    expect(route('received.index', absolute: false))->toBe('/transfers/received')
        ->and(route('received.show', $received, false))->toBe('/transfers/received/'.$received->id);
    $this->get('/transfers/received')->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('received/index', false));
    $this->get('/transfers/received/'.$received->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('received/show', false));
    $this->get('/transfers/'.$sent->id)->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('transfers/show', false));
    $this->get('/transfers/not-a-transfer')->assertNotFound();
});

it('searches received files by sender or Drop title', function (): void {
    $owner = User::factory()->create();
    receivedTransfer($owner, ['sender_name' => 'Giulia Neri', 'title' => 'Foto e video — Fiera Rimini']);
    $logos = receivedTransfer($owner, ['sender_name' => 'Studio Vela', 'title' => 'Loghi e linee guida']);

    $this->actingAs($owner)->get(route('received.index', ['search' => ' vela ']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('received.data', 1)->where('received.data.0.id', $logos->id)->where('search', 'vela')->where('totals.total', 2));
    $this->get(route('received.index', ['search' => 'LOGHI']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('received.data', 1)->where('received.data.0.id', $logos->id));
    $this->get(route('received.index', ['search' => '100%']))->assertInertia(fn (Assert $page): Assert => $page->has('received.data', 0));
});

it('shows a received item to its owner only and marks it opened', function (): void {
    $owner = User::factory()->create();
    $transfer = receivedTransfer($owner, ['message' => 'Ecco la selezione.']);

    $this->actingAs(User::factory()->create())->get(route('received.show', $transfer))->assertForbidden();
    $this->flushSession()->actingAs($owner)->get(route('received.show', Transfer::factory()->for($owner)->create()))->assertForbidden();

    $this->get(route('received.show', $transfer))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('received/show', false)
        ->where('transfer.id', $transfer->id)
        ->where('transfer.message', 'Ecco la selezione.')
        ->where('transfer.drop.open', true)
        ->where('transfer.token', $transfer->token)
        ->where('transfer.is_new', true));
    expect($transfer->refresh()->first_opened_at)->not->toBeNull();

    $this->get(route('received.show', $transfer))->assertInertia(fn (Assert $page): Assert => $page->where('transfer.is_new', false));
});

it('lets the owner download received files through the shared routes', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $owner = User::factory()->create();
    $transfer = receivedTransfer($owner);
    $file = $transfer->files()->sole();
    Storage::disk('local')->put($file->path, str_repeat('a', $file->size));

    $this->actingAs($owner)->postJson(route('shared.files.download', [$transfer->token, $file->id]))->assertOk()->assertJsonStructure(['url']);
    $this->flushSession()->actingAs(User::factory()->create())->postJson(route('shared.files.download', [$transfer->token, $file->id]))->assertForbidden();
});

it('deletes received files for the owner', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $transfer = receivedTransfer($owner, ['sender_name' => 'Giulia Neri']);

    $this->actingAs(User::factory()->create())->delete(route('received.destroy', $transfer))->assertForbidden();
    $this->flushSession()->actingAs($owner)->delete(route('received.destroy', $transfer))
        ->assertRedirect(route('received.index'))
        ->assertInertiaFlash('toast.title', 'Files deleted');

    expect($transfer->refresh()->revoked_at)->not->toBeNull();
    Queue::assertPushed(PurgeTransfer::class, fn (PurgeTransfer $job): bool => $job->transferId === $transfer->id);
    $this->get(route('received.show', $transfer))->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfer.available', false)
        ->where('transfer.gone_at', $transfer->revoked_at?->toIso8601String())
        ->where('transfer.token', null));
});

it('purges received files when their keep period ends, not 30 days later', function (): void {
    Queue::fake();
    $owner = User::factory()->create();
    $received = receivedTransfer($owner, ['expires_at' => now()->subMinute()]);
    $sent = Transfer::factory()->for($owner)->create(['expires_at' => now()->subDay()]);

    $this->artisan('filemax:cleanup')->assertSuccessful();

    Queue::assertPushed(PurgeTransfer::class, fn (PurgeTransfer $job): bool => $job->transferId === $received->id);
    Queue::assertNotPushed(PurgeTransfer::class, fn (PurgeTransfer $job): bool => $job->transferId === $sent->id);

    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    new PurgeTransfer($received->id)->handle(resolve(TransferStorage::class));
    new PurgeTransfer($sent->id)->handle(resolve(TransferStorage::class));
    expect($received->refresh()->purged_at)->not->toBeNull()->and($sent->refresh()->purged_at)->toBeNull();
});

it('never sends expiry reminders for received files', function (): void {
    Notification::fake();
    receivedTransfer(User::factory()->create(), ['expires_at' => now()->addHours(12)]);

    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertNotSentTo(User::all(), TransferExpiring::class);
});

it('writes the received email in the owner’s timezone with uploader text escaped', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00', 'UTC'));
    $owner = User::factory()->create();
    $drop = Drop::factory()->for($owner)->create(['title' => 'Foto e video — Fiera Rimini', 'closes_at' => now()->setDate(2026, 10, 21)->setTime(8, 24)]);
    $transfer = Transfer::factory()->for($owner)->received()->create([
        'drop_id' => $drop->id,
        'sender_name' => "O'Brien & Figli [Click](https://evil.example)",
        'message' => 'Ecco ![x](https://track.example/pixel) la selezione.',
        'expires_at' => now()->setDate(2026, 11, 13)->setTime(10, 2),
    ]);
    foreach (['Stand_001.jpg' => 14_200_000, 'Stand_002.jpg' => 13_800_000, 'Reel.mp4' => 486_000_000, 'd.jpg' => 1, 'e.jpg' => 1, 'f.jpg' => 1] as $name => $size) {
        TransferFile::factory()->create(['transfer_id' => $transfer->id, 'original_name' => $name, 'size' => $size]);
    }

    $mail = new DropReceived($transfer->refresh())->toMail($owner);
    $html = (string) $mail->render();
    $text = (string) resolve(Markdown::class)->renderText($mail->markdown, $mail->data());

    expect($mail->subject)->toBe("O'Brien & Figli [Click](https://evil.example) sent you 6 files")
        ->and($mail->actionUrl)->toBe(route('received.show', $transfer))
        ->and($html)->not->toContain('href="https://evil.example"')
        ->and($html)->not->toContain('<img src="https://track.example')
        ->and($html)->not->toContain('&amp;#039;')
        ->and($html)->toContain("O'Brien &amp; Figli [Click](https://evil.example) sent you 6 files")
        ->and($html)->toContain('Ecco ![x](https://track.example/pixel) la selezione.')
        ->and($html)->toContain('Stand_001.jpg — 14.2 MB')
        ->and($html)->toContain('Reel.mp4 — 486 MB')
        ->and($html)->toContain('and 1 more file')
        ->and($html)->not->toContain('f.jpg')
        ->and($text)->toContain('Stand_001.jpg — 14.2 MB')
        ->and($html)->toContain('kept until 13 Nov 2026')
        ->and($html)->toContain('open until 21 Oct 2026, 10:24')
        ->and($html)->toContain('href="'.route('account.settings.notifications').'"');

    $single = Transfer::factory()->for($owner)->received()->create(['drop_id' => $drop->id, 'sender_name' => 'Marco']);
    TransferFile::factory()->create(['transfer_id' => $single->id]);
    expect(new DropReceived($single->refresh())->toMail($owner)->subject)->toBe('Marco sent you 1 file');
});
