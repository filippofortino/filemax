<?php

declare(strict_types=1);

use App\Jobs\PurgeTransfer;
use App\Models\Drop;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Notifications\DropReceived;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
});

/** @param list<array{name: string, size: int}> $files */
function startDropSubmission(Drop $drop, array $files = [['name' => 'Stand_001.jpg', 'size' => 5]], array $overrides = []): TestResponse
{
    return test()->postJson(route('shared.drops.submissions.store', $drop->token), [
        'sender_name' => 'Giulia Neri',
        'message' => 'Ecco la selezione.',
        'files' => $files,
        ...$overrides,
    ]);
}

function putDropPart(string $transferId, string $fileId, string $content, int $part = 1): TestResponse
{
    return test()->call('PUT', route('shared.drops.uploads.upload', [$transferId, $fileId, $part]), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $content);
}

/** Uploads one file through a Drop and returns the submission's id. */
function sendThroughDrop(Drop $drop, string $content = 'hello'): string
{
    $response = startDropSubmission($drop, [['name' => 'Stand_001.jpg', 'size' => mb_strlen($content, '8bit')]])->assertCreated();
    $id = $response->json('submission.id');
    $fileId = $response->json('submission.files.0.id');
    test()->postJson(route('shared.drops.uploads.sign', [$id, $fileId, 1]))->assertOk();
    putDropPart($id, $fileId, $content)->assertOk();
    test()->postJson(route('shared.drops.uploads.complete', [$id, $fileId]))->assertOk();
    test()->postJson(route('shared.drops.uploads.finalize', $id))->assertOk();

    return $id;
}

it('shows an open Drop to anyone with the link', function (): void {
    $owner = User::factory()->create(['name' => 'Filippo Fortino']);
    $drop = Drop::factory()->for($owner)->create(['title' => 'Foto e video', 'file_types' => ['images'], 'extensions' => ['indd'], 'max_file_size' => 2_000_000_000]);

    $this->get(route('shared.drops.show', $drop->token))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('shared/drop', false)
            ->where('drop.title', 'Foto e video')
            ->where('drop.owner.name', 'Filippo Fortino')
            ->where('drop.open', true)
            ->where('drop.max_file_size', 2_000_000_000)
            ->where('drop.accepted_extensions', [...Drop::FILE_TYPES['images'], 'indd'])
            ->missing('drop.owner.email')
            ->missing('drop.id'));
});

it('hides the owner’s name and photo on their Drops when they hide it on transfers', function (): void {
    $owner = User::factory()->create(['name' => 'Filippo Fortino']);
    $owner->settings()->create(['show_name_on_transfers' => false]);
    $open = Drop::factory()->for($owner)->create();
    $closed = Drop::factory()->for($owner)->closed()->create();

    $this->get(route('shared.drops.show', $open->token))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('drop.owner', null))
        ->assertDontSee('Filippo Fortino');
    $this->get(route('shared.drops.show', $closed->token))->assertGone()
        ->assertInertia(fn (Assert $page): Assert => $page->where('drop.owner', null));
    startDropSubmission($open, overrides: ['sender_name' => ''])->assertUnprocessable()
        ->assertJsonPath('errors.sender_name.0', 'Enter your name so the recipient knows who sent the files.');
});

it('shows closed Drops as gone and unknown links as missing', function (): void {
    $closed = Drop::factory()->closed()->create();
    $expired = Drop::factory()->expired()->create();

    $this->get(route('shared.drops.show', $closed->token))->assertGone()
        ->assertInertia(fn (Assert $page): Assert => $page->where('drop.open', false)->where('drop.closed_early', true));
    $this->get(route('shared.drops.show', $expired->token))->assertGone()
        ->assertInertia(fn (Assert $page): Assert => $page->where('drop.open', false)->where('drop.closed_early', false));
    $this->get('/d/missing-drop-link')->assertNotFound()
        ->assertInertia(fn (Assert $page): Assert => $page->component('shared/drop', false)->where('drop', null));
});

it('sends files to the owner through the whole upload flow, once', function (): void {
    Notification::fake();
    $drop = Drop::factory()->create(['keep_days' => 14]);

    $response = startDropSubmission($drop)->assertCreated()
        ->assertJsonMissingPath('submission.token')
        ->assertJsonMissingPath('submission.url')
        ->assertJsonPath('submission.files.0.original_name', 'Stand_001.jpg');
    $id = $response->json('submission.id');
    $fileId = $response->json('submission.files.0.id');

    $signed = $this->postJson(route('shared.drops.uploads.sign', [$id, $fileId, 1]))->assertOk()->assertJsonPath('completed', false);
    expect($signed->json('url'))->toBe(route('shared.drops.uploads.upload', [$id, $fileId, 1]));
    putDropPart($id, $fileId, 'hello')->assertOk();
    $this->postJson(route('shared.drops.uploads.complete', [$id, $fileId]))->assertOk()->assertJsonPath('file.status', 'ready');
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertOk()->assertExactJson(['sent' => true, 'open' => true, 'closes_at' => $drop->closes_at->toIso8601String()]);
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertOk()->assertJsonPath('sent', true);

    $transfer = Transfer::query()->findOrFail($id);
    expect($transfer->drop_id)->toBe($drop->id)
        ->and($transfer->user_id)->toBe($drop->user_id)
        ->and($transfer->visibility)->toBe('drop')
        ->and($transfer->status)->toBe('ready')
        ->and($transfer->title)->toBe($drop->title)
        ->and($transfer->sender_name)->toBe('Giulia Neri')
        ->and($transfer->message)->toBe('Ecco la selezione.')
        ->and($transfer->received_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($transfer->expires_at?->toDateTimeString())->toBe(now()->addDays(14)->toDateTimeString())
        ->and(Storage::disk('local')->get(TransferFile::query()->findOrFail($fileId)->path))->toBe('hello');
    Notification::assertSentToTimes($drop->user, DropReceived::class, 1);

    $this->postJson(route('shared.drops.uploads.sign', [$id, $fileId, 1]))->assertConflict();
    putDropPart($id, $fileId, 'later')->assertConflict();
});

it('lets a signed-in colleague send files to someone else’s Drop', function (): void {
    Notification::fake();
    $drop = Drop::factory()->create();
    $this->actingAs(User::factory()->create());

    $id = sendThroughDrop($drop);

    expect(Transfer::query()->findOrFail($id)->user_id)->toBe($drop->user_id);
    Notification::assertSentToTimes($drop->user, DropReceived::class, 1);
});

it('only lets the session that started a submission touch it', function (): void {
    Queue::fake();
    $drop = Drop::factory()->create();
    $response = startDropSubmission($drop)->assertCreated();
    $id = $response->json('submission.id');
    $fileId = $response->json('submission.files.0.id');

    $this->flushSession();

    $this->postJson(route('shared.drops.uploads.sign', [$id, $fileId, 1]))->assertForbidden();
    putDropPart($id, $fileId, 'hello')->assertForbidden();
    $this->postJson(route('shared.drops.uploads.complete', [$id, $fileId]))->assertForbidden();
    $this->deleteJson(route('shared.drops.uploads.remove', [$id, $fileId]))->assertForbidden();
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertForbidden();
    $this->deleteJson(route('shared.drops.uploads.cancel', $id))->assertForbidden();
    expect(Transfer::query()->findOrFail($id)->status)->toBe('uploading')->and(Transfer::query()->findOrFail($id)->revoked_at)->toBeNull();
});

it('stops taking bytes a day after the Drop closes', function (): void {
    $drop = Drop::factory()->create();
    $response = startDropSubmission($drop)->assertCreated();
    $id = $response->json('submission.id');
    $fileId = $response->json('submission.files.0.id');

    $this->flushSession()->actingAs($drop->user)->post(route('drops.close', $drop));
    $this->flushSession()->withSession(['drop_uploads.'.$id => true]);
    putDropPart($id, $fileId, 'hello')->assertOk();

    $this->travel(1)->day();
    $this->postJson(route('shared.drops.uploads.complete', [$id, $fileId]))->assertForbidden();
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertForbidden();
});

it('keeps the Drop routes away from ordinary transfers', function (): void {
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->uploading()->create();
    $file = TransferFile::factory()->create(['transfer_id' => $transfer->id, 'size' => 5]);
    $this->actingAs($owner);

    $this->postJson(route('shared.drops.uploads.finalize', $transfer))->assertForbidden();
    $this->deleteJson(route('shared.drops.uploads.cancel', $transfer))->assertForbidden();
    $this->postJson(route('shared.drops.uploads.sign', [$transfer, $file, 1]))->assertOk();
    expect($transfer->refresh()->status)->toBe('uploading')->and($transfer->revoked_at)->toBeNull();
});

it('keeps received files away from the uploader and from the sent-transfer routes', function (): void {
    Notification::fake();
    $drop = Drop::factory()->create();
    $transfer = Transfer::query()->findOrFail(sendThroughDrop($drop));
    $file = $transfer->files()->sole();

    $this->postJson(route('shared.files.download', [$transfer->token, $file->id]))->assertForbidden();
    $this->postJson(route('shared.download', $transfer->token))->assertForbidden();
    $this->get(route('shared.show', $transfer->token))->assertForbidden();

    $this->actingAs($drop->user);
    $this->get(route('transfers.show', $transfer))->assertForbidden();
    $this->deleteJson(route('transfers.destroy', $transfer))->assertForbidden();
    $this->postJson(route('transfers.extend', $transfer), ['expected_expires_at' => $transfer->expires_at?->toIso8601String()])->assertForbidden();
    $this->postJson(route('shared.files.download', [$transfer->token, $file->id]))->assertOk();
});

it('never sends an empty submission', function (): void {
    $drop = Drop::factory()->create();
    $response = startDropSubmission($drop)->assertCreated();
    $id = $response->json('submission.id');

    $this->deleteJson(route('shared.drops.uploads.remove', [$id, $response->json('submission.files.0.id')]))->assertOk();
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertUnprocessable()->assertJsonValidationErrors('files');
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertUnprocessable();
    expect(Transfer::query()->findOrFail($id)->status)->toBe('uploading');
});

it('lets the uploader cancel before sending, but not after', function (): void {
    Queue::fake();
    Notification::fake();
    $drop = Drop::factory()->create();
    $id = startDropSubmission($drop)->assertCreated()->json('submission.id');

    $this->deleteJson(route('shared.drops.uploads.cancel', $id))->assertOk();
    expect(Transfer::query()->findOrFail($id)->revoked_at)->not->toBeNull();
    Queue::assertPushed(PurgeTransfer::class, fn (PurgeTransfer $job): bool => $job->transferId === $id);
    $this->postJson(route('shared.drops.uploads.finalize', $id))->assertGone();

    $sent = sendThroughDrop($drop);
    $this->deleteJson(route('shared.drops.uploads.cancel', $sent))->assertConflict();
    expect(Transfer::query()->findOrFail($sent)->revoked_at)->toBeNull();
});

it('refuses new submissions once the Drop is closed', function (): void {
    $drop = Drop::factory()->closed()->create();

    startDropSubmission($drop, overrides: ['sender_name' => ''])->assertGone();
    expect(Transfer::query()->count())->toBe(0);
});

it('validates what the uploader sends', function (array $input, string $field): void {
    $drop = Drop::factory()->create(['file_types' => ['images', 'videos'], 'max_file_size' => 2_000_000_000, 'max_submission_size' => 3_000_000_000]);

    startDropSubmission($drop, $input['files'] ?? [['name' => 'Stand_001.jpg', 'size' => 5]], $input['overrides'] ?? [])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Transfer::query()->count())->toBe(0);
})->with([
    'missing name' => [['overrides' => ['sender_name' => '']], 'sender_name'],
    'line break in name' => [['overrides' => ['sender_name' => "Giulia\nClick here"]], 'sender_name'],
    'long message' => [['overrides' => ['message' => str_repeat('a', 2001)]], 'message'],
    'no files' => [['files' => []], 'files'],
    'too many files' => [['files' => array_fill(0, 501, ['name' => 'a.jpg', 'size' => 1])], 'files'],
    'type not accepted' => [['files' => [['name' => 'Stand_001.jpg', 'size' => 5], ['name' => 'RAW_selezione.zip', 'size' => 5]]], 'files.1.name'],
    'over the per-file size' => [['files' => [['name' => 'Backstage.mov', 'size' => 2_000_000_001]]], 'files.0.size'],
    'over the total' => [['files' => [['name' => 'a.mov', 'size' => 2_000_000_000], ['name' => 'b.mov', 'size' => 1_500_000_000]]], 'files'],
    'right-to-left override in a file name' => [['files' => [['name' => "invoice\u{202E}gpj.exe", 'size' => 5]]], 'files.0.name'],
    'negative size' => [['files' => [['name' => 'a.jpg', 'size' => -1]]], 'files.0.size'],
]);

it('limits new submissions per network', function (): void {
    $drop = Drop::factory()->create();

    foreach (range(1, 30) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.dechex($attempt)]);
        startDropSubmission($drop, overrides: ['sender_name' => ''])->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::ffff']);
    startDropSubmission($drop)->assertTooManyRequests()->assertJsonPath('message', 'Too many uploads from your network. Try again later.');
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db9::1']);
    startDropSubmission($drop)->assertCreated();

    foreach (range(1, 30) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '::ffff:198.51.100.7']);
        startDropSubmission($drop, overrides: ['sender_name' => ''])->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '::ffff:198.51.100.7']);
    startDropSubmission($drop)->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '::ffff:203.0.113.9']);
    startDropSubmission($drop)->assertCreated();
});

it('emails the owner at most ten times an hour per Drop, and only if they want it', function (): void {
    Notification::fake();
    $drop = Drop::factory()->create();

    foreach (range(1, 11) as $attempt) {
        sendThroughDrop($drop);
    }

    Notification::assertSentToTimes($drop->user, DropReceived::class, 10);
    expect(Transfer::receivedBy($drop->user)->count())->toBe(11);

    $quiet = Drop::factory()->create();
    $quiet->user->settings()->create(['notify_drop_received' => false]);
    sendThroughDrop($quiet);
    Notification::assertNotSentTo($quiet->user, DropReceived::class);
});

it('re-checks the email setting when the email goes out', function (): void {
    Notification::fake();
    $drop = Drop::factory()->create();
    $transfer = Transfer::query()->findOrFail(sendThroughDrop($drop));
    $notification = new DropReceived($transfer);

    expect($notification->shouldSend($drop->user, 'mail'))->toBeTrue();
    $drop->user->settings()->create(['notify_drop_received' => false]);
    expect(new DropReceived($transfer->fresh() ?? $transfer)->shouldSend($drop->user, 'mail'))->toBeFalse();
});
