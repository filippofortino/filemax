<?php

declare(strict_types=1);

use App\Jobs\PrepareTransferArchive;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Notifications\TransferDownloaded;
use App\Notifications\TransferExpiring;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    Notification::fake();
    $this->extend = fn (Transfer $transfer): TestResponse => $this->actingAs($transfer->user)
        ->postJson(route('transfers.extend', $transfer), ['expected_expires_at' => $transfer->expires_at?->toIso8601String()])
        ->assertOk();
});

it('schedules expiry reminders every three hours', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $reminder = collect(resolve(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'filemax:send-expiry-reminders'));

    expect($reminder?->expression)->toBe('0 */3 * * *');
});

it('reminds the owner once when a transfer has 24 hours left', function (): void {
    $transfer = Transfer::factory()->create(['title' => 'Spot autunno']);
    $this->travelTo($transfer->expires_at?->subDay()->subSecond());
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travel(1)->second();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($transfer->user, TransferExpiring::class, 1);
    $mail = new TransferExpiring($transfer)->toMail($transfer->user);
    expect($mail->subject)->toBe('“Spot autunno” expires in 1 day')
        ->and($mail->actionText)->toBe('Extend transfer')
        ->and($mail->actionUrl)->toBe(route('transfers.show', $transfer))
        ->and((string) $mail->render())->toContain('href="'.route('account.settings.notifications').'"');
});

it('retries an expiry reminder when enqueueing fails', function (): void {
    $transfer = Transfer::factory()->create(['expires_at' => now()->addHours(23)]);
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Queue unavailable'));

    expect(fn () => $this->artisan('filemax:send-expiry-reminders')->run())->toThrow(RuntimeException::class, 'Queue unavailable');
    expect($transfer->refresh()->expiry_reminder_sent_at)->toBeNull();

    Notification::fake();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($transfer->user, TransferExpiring::class, 1);
    expect($transfer->refresh()->expiry_reminder_sent_at)->not->toBeNull();
});

it('rechecks expiry reminder eligibility when queued mail is delivered', function (array $attributes, bool $optOut, int $emails): void {
    Notification::swap(new ChannelManager(app()));
    config(['queue.default' => 'database']);
    $transfer = Transfer::factory()->create(['expires_at' => now()->addHours(23)]);
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(1);

    $transfer->update($attributes);
    if ($optOut) {
        $transfer->user->settings()->create(['notify_transfer_expiring' => false]);
    }

    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0])->assertSuccessful();

    $transport = Mail::getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    expect($transport->messages())->toHaveCount($emails)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
})->with([
    'still available with default preference' => [[], false, 1],
    'revoked' => fn (): array => [['revoked_at' => now()], false, 0],
    'purged' => fn (): array => [['purged_at' => now()], false, 0],
    'expired' => fn (): array => [['expires_at' => now()], false, 0],
    'extended outside the reminder window' => fn (): array => [['expires_at' => now()->addDays(8)], false, 0],
    'notifications disabled after enqueueing' => [[], true, 0],
]);

it('only reminds transfers that are still available', function (array $attributes): void {
    Transfer::factory()->create($attributes);
    $this->travel(6)->days();

    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertNothingSent();
})->with([
    'expired' => fn (): array => ['expires_at' => now()->addDays(6)],
    'deleted' => fn (): array => ['revoked_at' => now()],
    'purged' => fn (): array => ['purged_at' => now()],
    'uploading' => fn (): array => ['status' => 'uploading'],
]);

it('reminds by default unless the owner turned it off', function (): void {
    $remembered = Transfer::factory()->create();
    $optedOut = Transfer::factory()->create();
    $optedOut->user->settings()->create(['notify_transfer_expiring' => false]);
    $this->travel(6)->days();

    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertSentTo($remembered->user, TransferExpiring::class);
    Notification::assertNotSentTo($optedOut->user, TransferExpiring::class);
});

it('reminds once per transfer, even after it is extended', function (): void {
    $transfer = Transfer::factory()->create();
    $this->travel(6)->days();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    ($this->extend)($transfer);
    $this->travel(7)->days();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertSentToTimes($transfer->user, TransferExpiring::class, 1);
});

it('never reminds 1-day transfers, even once they are extended', function (): void {
    $transfer = Transfer::factory()->create(['expires_in_days' => 1, 'expires_at' => now()->addDay()]);
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    ($this->extend)($transfer);
    $this->travel(7)->days();
    $this->artisan('filemax:send-expiry-reminders')->assertSuccessful();

    Notification::assertNothingSent();
});

it('emails the owner once when someone else first downloads the transfer', function (string $download): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create(['title' => 'Spot autunno']);
    $file = TransferFile::factory()->for($transfer)->create();
    $transfer->user->settings()->create(['notify_transfer_downloaded' => true]);
    $url = $download === 'file' ? route('shared.files.download', [$transfer->token, $file]) : route('shared.download', $transfer->token);

    $this->actingAs($transfer->user)->postJson($url)->assertOk();
    Notification::assertNothingSent();
    expect($transfer->refresh()->first_downloaded_at)->toBeNull();

    Auth::logout();
    $this->postJson($url)->assertOk();
    $this->postJson($url)->assertOk();

    Notification::assertSentToTimes($transfer->user, TransferDownloaded::class, 1);
    expect($transfer->refresh()->first_downloaded_at)->not->toBeNull()->and($transfer->download_count)->toBe(3);
    $mail = new TransferDownloaded($transfer)->toMail($transfer->user);
    expect($mail->subject)->toBe('“Spot autunno” was downloaded')
        ->and($mail->actionUrl)->toBe(route('transfers.show', $transfer))
        ->and((string) $mail->render())->toContain('href="'.route('account.settings.notifications').'"');
})->with(['a single file' => 'file', 'the whole transfer' => 'archive']);

it('sends no download email by default, even when turned on after the first download', function (): void {
    $transfer = Transfer::factory()->create();
    $url = route('shared.files.download', [$transfer->token, TransferFile::factory()->for($transfer)->create()]);

    $this->postJson($url)->assertOk();
    $transfer->user->settings()->create(['notify_transfer_downloaded' => true]);
    $this->postJson($url)->assertOk();

    Notification::assertNothingSent();
    expect($transfer->refresh()->first_downloaded_at)->not->toBeNull();
});

it('does not announce a historical download as the first download', function (string $download): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create(['download_count' => 3, 'last_downloaded_at' => now()->subDay()]);
    $file = TransferFile::factory()->for($transfer)->create();
    $untouched = Transfer::factory()->create();
    $recorded = Transfer::factory()->create(['download_count' => 2, 'first_downloaded_at' => now()->subDays(2), 'last_downloaded_at' => now()->subDay()]);

    $migration = require database_path('migrations/2026_10_06_220037_backfill_first_downloaded_at_on_transfers.php');
    $migration->up();

    $transfer->user->settings()->create(['notify_transfer_downloaded' => true]);
    $url = $download === 'file' ? route('shared.files.download', [$transfer->token, $file]) : route('shared.download', $transfer->token);
    $this->postJson($url)->assertOk();

    Notification::assertNothingSent();
    expect($transfer->refresh()->first_downloaded_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($transfer->download_count)->toBe(4)
        ->and($untouched->refresh()->first_downloaded_at)->toBeNull()
        ->and($recorded->refresh()->first_downloaded_at?->equalTo(now()->subDays(2)))->toBeTrue();
})->with(['a single file' => 'file', 'the whole transfer' => 'archive']);
