<?php

declare(strict_types=1);

use App\Jobs\PrepareTransferArchive;
use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    Notification::fake();
});

/** @return array<string, mixed> */
function passwordTransferPayload(array $overrides = []): array
{
    return array_replace(['visibility' => 'public', 'expires_in_days' => 7, 'team_ids' => [], 'files' => [['name' => 'private.txt', 'size' => 4]]], $overrides);
}

it('defaults protection off and stores only a hidden hash when enabled', function (): void {
    $this->actingAs(User::factory()->create());
    $plain = $this->postJson(route('transfers.store'), passwordTransferPayload())->assertCreated()->assertJsonPath('transfer.password_protected', false);
    expect(Transfer::query()->findOrFail($plain->json('transfer.id'))->password_hash)->toBeNull();
    $protected = $this->postJson(route('transfers.store'), passwordTransferPayload(['password_protected' => true, 'password' => '  Transfer123  ']))
        ->assertCreated()->assertJsonPath('transfer.password_protected', true)->assertJsonMissingPath('transfer.password')->assertJsonMissingPath('transfer.password_hash');
    $transfer = Transfer::query()->findOrFail($protected->json('transfer.id'));
    expect(Hash::check('  Transfer123  ', $transfer->password_hash))->toBeTrue()
        ->and(Hash::check('Transfer123', $transfer->password_hash))->toBeFalse()
        ->and($transfer->toArray())->not->toHaveKey('password_hash');
});

it('rejects invalid creation passwords without silently dropping protection', function (mixed $password): void {
    $this->actingAs(User::factory()->create())->postJson(route('transfers.store'), passwordTransferPayload(['password_protected' => true, 'password' => $password]))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    expect(Transfer::query()->count())->toBe(0);
})->with([
    'missing' => [null], 'empty' => [''], 'short' => ['short'], 'not a string' => [['password']],
    'too many bytes' => [str_repeat('a', 73)], 'multibyte overflow' => [str_repeat('é', 37)], 'nul' => ["Transfer\0password"],
]);

it('accepts the bcrypt byte boundary without truncating passwords', function (): void {
    $password = str_repeat('é', 36);
    $response = $this->actingAs(User::factory()->create())->postJson(route('transfers.store'), passwordTransferPayload(['password_protected' => true, 'password' => $password]))->assertCreated();
    expect(Hash::check($password, Transfer::query()->findOrFail($response->json('transfer.id'))->password_hash))->toBeTrue();
});

it('rejects passwords when protection is off or omitted', function (array $protection): void {
    $this->actingAs(User::factory()->create())->postJson(route('transfers.store'), passwordTransferPayload([...$protection, 'password' => 'Transfer123']))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
})->with(['omitted' => [[]], 'off' => [['password_protected' => false]]]);

it('rejects password protection on teams both at creation and sharing updates', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $owner->teams()->attach($team);
    $this->actingAs($owner)->postJson(route('transfers.store'), passwordTransferPayload([
        'visibility' => 'teams', 'team_ids' => [$team->id], 'password_protected' => true, 'password' => 'Transfer123',
    ]))->assertUnprocessable();
    expect(Transfer::query()->count())->toBe(0);
    $transfer = Transfer::factory()->for($owner)->create(['visibility' => 'teams']);
    $this->patchJson(route('transfers.update', $transfer), ['team_ids' => [$team->id], 'password_protected' => true, 'password' => 'Transfer123'])->assertUnprocessable();
    expect($transfer->refresh()->password_hash)->toBeNull();
});

it('prevents adding replacing or removing passwords through sharing updates', function (bool $protected, array $changes): void {
    $hash = $protected ? Hash::make('Transfer123') : null;
    $transfer = Transfer::factory()->create(['password_hash' => $hash]);
    $team = Team::factory()->create();
    $transfer->user->teams()->attach($team);
    $this->actingAs($transfer->user)->patchJson(route('transfers.update', $transfer), ['team_ids' => [$team->id], ...$changes])->assertUnprocessable();
    expect($transfer->refresh()->password_hash)->toBe($hash);
})->with([
    'add' => [false, ['password_protected' => true, 'password' => 'NewPassword123']],
    'replace' => [true, ['password' => 'NewPassword123']],
    'remove' => [true, ['password_protected' => false, 'password_hash' => null]],
]);

it('preserves the password across failed upload finalization and retries', function (): void {
    $response = $this->actingAs(User::factory()->create())->postJson(route('transfers.store'), passwordTransferPayload(['password_protected' => true, 'password' => 'Transfer123']))->assertCreated();
    $transfer = Transfer::query()->findOrFail($response->json('transfer.id'));
    $file = $transfer->files()->firstOrFail();
    $hash = $transfer->password_hash;
    $this->postJson(route('transfers.uploads.finalize', $transfer))->assertUnprocessable();
    Storage::disk('local')->put($file->path, 'test');
    $this->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertOk();
    $this->postJson(route('transfers.uploads.finalize', $transfer), ['password' => 'OtherPassword', 'password_protected' => false])
        ->assertOk()->assertJsonPath('transfer.password_protected', true);
    expect($transfer->refresh()->password_hash)->toBe($hash);
});

it('shows a private password gate without file details or opening side effects and respects current sender privacy', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123'), 'title' => 'Secret title', 'message' => 'Secret message']);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'secret-file.txt']);
    $url = route('shared.show', $transfer->token);
    $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page->component('shared/password', false)
            ->where('token', $transfer->token)->where('sender.name', $transfer->user->name)
            ->missing('transfer')->missing('files')->missing('title')->missing('message')->missing('password_hash'))
        ->assertDontSee('Secret title')->assertDontSee('Secret message')->assertDontSee('secret-file.txt');
    expect($transfer->refresh()->first_opened_at)->toBeNull()->and($transfer->download_count)->toBe(0);
    $transfer->user->settings()->create(['show_name_on_transfers' => false]);
    $this->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('sender', null))
        ->assertDontSee($transfer->user->name)->assertDontSee($transfer->user->email);
});

it('unlocks only the requested transfer and rotates the session without retaining the password', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $other = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $this->get(route('shared.show', $transfer->token));
    $sessionId = session()->getId();
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])
        ->assertRedirect(route('shared.show', $transfer->token))->assertSessionHas('unlocked_transfers.'.$transfer->id, true)->assertSessionMissing('_old_input.password');
    expect(session()->getId())->not->toBe($sessionId)->and(json_encode(session()->all()))->not->toContain('Transfer123');
    $this->get(route('shared.show', $transfer->token))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/show', false)->where('transfer.password_protected', true)->missing('transfer.password_hash'));
    $this->get(route('shared.show', $other->token))->assertInertia(fn (Assert $page): Assert => $page->component('shared/password', false));
    expect($transfer->refresh()->first_opened_at)->not->toBeNull()->and($other->refresh()->first_opened_at)->toBeNull();
    $this->flushSession();
    $this->get(route('shared.show', $transfer->token))->assertInertia(fn (Assert $page): Assert => $page->component('shared/password', false));
});

it('rejects wrong passwords without flashing them and throttles across sessions', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $url = route('shared.unlock', $transfer->token);
    $this->from(route('shared.show', $transfer->token))->post($url, ['password' => 'Incorrect123'])
        ->assertRedirect(route('shared.show', $transfer->token))->assertSessionHasErrors('password')
        ->assertSessionMissing('_old_input.password')->assertSessionMissing('unlocked_transfers.'.$transfer->id);
    for ($attempt = 1; $attempt < 5; $attempt++) {
        $this->postJson($url, ['password' => 'Incorrect123'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    $this->flushSession();
    $this->postJson($url, ['password' => 'Transfer123'])->assertUnprocessable()->assertJsonValidationErrors('password')->assertSee('Too many tries');
    $this->travel(61)->seconds();
    $this->post($url, ['password' => 'Transfer123'])->assertRedirect(route('shared.show', $transfer->token));
});

it('isolates failed password limits by transfer and IP', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $other = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('shared.unlock', $transfer->token), ['password' => 'Incorrect123'])->assertUnprocessable();
    }

    $this->post(route('shared.unlock', $other->token), ['password' => 'Transfer123'])->assertRedirect(route('shared.show', $other->token));
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])
        ->assertRedirect(route('shared.show', $transfer->token));
});

it('allows the owner without a password and exposes only the protection flag for badges', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $file = TransferFile::factory()->for($transfer)->create();
    $this->actingAs($transfer->user)->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfers.data.0.password_protected', true)->missing('transfers.data.0.password_hash'));
    $this->get(route('transfers.show', $transfer))->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfer.password_protected', true)->missing('transfer.password_hash'));
    $this->get(route('shared.show', $transfer->token))->assertInertia(fn (Assert $page): Assert => $page->component('shared/show', false));
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk();
});

it('requires passwords from signed in non owners including administrators', function (): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $this->actingAs(User::factory()->admin()->create())->get(route('shared.show', $transfer->token))
        ->assertInertia(fn (Assert $page): Assert => $page->component('shared/password', false));
    $this->postJson(route('shared.download', $transfer->token))->assertForbidden();
});

it('protects all application download endpoints and copied content links without unauthorized side effects', function (): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123'), 'archive_status' => 'ready', 'archive_path' => 'archives/private.zip']);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');
    Storage::disk('local')->put($transfer->archive_path, 'zip bytes');
    $content = URL::temporarySignedRoute('shared.files.content', now()->addMinutes(5), ['transfer' => $transfer->token, 'file' => $file->id]);
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertForbidden();
    $this->get($content)->assertForbidden();
    $this->postJson(route('shared.download', $transfer->token))->assertForbidden();
    $this->getJson(route('shared.archive', $transfer->token))->assertForbidden();
    $this->get(route('shared.archive.download', $transfer->token))->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(0)->and($file->refresh()->download_count)->toBe(0);
    Queue::assertNothingPushed();
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertRedirect();
    $response = $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk();
    $this->get($response->json('url'))->assertOk()->assertStreamedContent('test');
    $this->postJson(route('shared.download', $transfer->token))->assertOk();
    $this->getJson(route('shared.archive', $transfer->token))->assertOk();
    $this->get(route('shared.archive.download', $transfer->token))->assertOk()->assertStreamedContent('zip bytes');
    expect($transfer->refresh()->download_count)->toBe(2)->and($file->refresh()->download_count)->toBe(1);
    $this->flushSession();
    $this->get($response->json('url'))->assertForbidden();
    $this->get(route('shared.archive.download', $transfer->token))->assertForbidden();
});

it('cannot schedule archive work before unlocking', function (): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $this->postJson(route('shared.download', $transfer->token))->assertForbidden();
    Queue::assertNothingPushed();
    expect($transfer->refresh()->archive_status)->toBeNull();
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertRedirect();
    $this->postJson(route('shared.download', $transfer->token))->assertOk()->assertJsonPath('status', 'pending');
    Queue::assertPushed(PrepareTransferArchive::class, 1);
});

it('rechecks availability after unlocking and refuses unavailable unlock requests', function (string $unavailable): void {
    $transfer = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertRedirect();
    $transfer->update($unavailable === 'status' ? ['status' => 'uploading'] : [$unavailable => now()]);
    $this->get(route('shared.show', $transfer->token))->assertGone()->assertInertia(fn (Assert $page): Assert => $page->component('shared/unavailable', false));
    $this->postJson(route('shared.download', $transfer->token))->assertGone();
    $this->postJson(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertGone();
})->with(['expires_at', 'revoked_at', 'purged_at', 'status']);

it('does not unlock unprotected or team transfers', function (string $visibility): void {
    $transfer = Transfer::factory()->create(['visibility' => $visibility]);
    $this->postJson(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertForbidden();
})->with(['public', 'teams']);

it('issues remote URLs only after authorization with the existing capped expiry', function (int $remainingSeconds): void {
    $transfer = Transfer::factory()->create([
        'password_hash' => Hash::make('Transfer123'), 'expires_at' => now()->addSeconds($remainingSeconds),
        'archive_status' => 'ready', 'archive_path' => 'archives/private.zip',
    ]);
    $file = TransferFile::factory()->for($transfer)->create();
    $disk = Mockery::mock(AwsS3V3Adapter::class);
    config(['filemax.disk' => 's3']);
    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    $expires = now()->addSeconds(min(300, $remainingSeconds));
    $disk->shouldReceive('temporaryUrl')->once()->with($file->path, Mockery::on(fn (CarbonInterface $value): bool => $value->timestamp === $expires->timestamp), Mockery::type('array'))
        ->andReturn('https://storage.example.test/private-file?signature=temporary');
    $disk->shouldReceive('temporaryUrl')->once()->with($transfer->archive_path, Mockery::on(fn (CarbonInterface $value): bool => $value->timestamp === $expires->timestamp), Mockery::type('array'))
        ->andReturn('https://storage.example.test/private-archive?signature=temporary');
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertForbidden();
    $this->get(route('shared.archive.download', $transfer->token))->assertForbidden();
    $this->post(route('shared.unlock', $transfer->token), ['password' => 'Transfer123'])->assertRedirect();
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk()
        ->assertJsonPath('url', 'https://storage.example.test/private-file?signature=temporary');
    $this->get(route('shared.archive.download', $transfer->token))->assertRedirect('https://storage.example.test/private-archive?signature=temporary')
        ->assertHeader('Cache-Control', 'no-store, private');
})->with([30, 600]);
