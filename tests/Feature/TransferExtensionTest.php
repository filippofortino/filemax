<?php

declare(strict_types=1);

use App\Jobs\PurgeTransfer;
use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Services\TransferStorage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo(now()->startOfSecond());
    $this->withoutVite();
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
});

it('extends an eligible transfer from the later of expiry and now', function (int $remainingDays, int $expectedDays): void {
    $transfer = Transfer::factory()->create(['expires_at' => now()->addDays($remainingDays)]);

    $this->actingAs($transfer->user)->postJson(route('transfers.extend', $transfer), [
        'expected_expires_at' => $transfer->expires_at->toIso8601String(),
    ])->assertOk()->assertJsonPath('expires_at', now()->addDays($expectedDays)->toIso8601String());

    expect($transfer->refresh()->expires_at->equalTo(now()->addDays($expectedDays)))->toBeTrue()
        ->and($transfer->isAvailable())->toBeTrue();
})->with(['active' => [2, 9], 'seven days remaining' => [7, 14], 'expires now' => [0, 7], 'expired' => [-29, 7]]);

it('exposes and enforces the exact extension window', function (int $expiryOffset, bool $eligible): void {
    $transfer = Transfer::factory()->create(['expires_at' => now()->addSeconds($expiryOffset)]);
    $expiry = $transfer->expires_at->toIso8601String();
    $this->actingAs($transfer->user)->get(route('transfers.show', $transfer))
        ->assertInertia(fn (Assert $page): Assert => $page->where('transfer.can_extend', $eligible));

    $response = $this->postJson('/transfers/'.$transfer->id.'/extend', ['expected_expires_at' => $expiry]);

    if ($eligible) {
        $response->assertOk();
    } else {
        $response->assertUnprocessable()->assertJsonValidationErrors('expected_expires_at');
        expect($transfer->refresh()->expires_at->toIso8601String())->toBe($expiry);
    }
})->with([
    'more than seven days' => [604801, false],
    'exactly seven days' => [604800, true],
    'just before retention ends' => [-2591999, true],
    'exact retention cutoff' => [-2592000, false],
    'past retention without completed cleanup' => [-2592001, false],
]);

it('rejects deleted purged and incomplete transfers', function (array $attributes): void {
    $transfer = Transfer::factory()->create($attributes);
    $this->actingAs($transfer->user)->get(route('transfers.show', $transfer))
        ->assertInertia(fn (Assert $page): Assert => $page->where('transfer.can_extend', false));

    $this->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => ($transfer->expires_at ?? now())->toIso8601String(),
    ])->assertUnprocessable()->assertJsonValidationErrors('expected_expires_at');
})->with([
    'deleted' => fn (): array => ['revoked_at' => now()],
    'purged' => fn (): array => ['purged_at' => now()],
    'uploading' => [['status' => 'uploading']],
    'no expiry' => [['expires_at' => null]],
]);

it('requires the verified eligible owner rather than team membership or admin status', function (string $actor): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $user = match ($actor) {
        'admin' => User::factory()->admin()->create(),
        'member' => User::factory()->create(),
        'unverified' => tap($transfer->user, fn (User $user): bool => $user->update(['email_verified_at' => null])),
        'ineligible' => tap($transfer->user, fn (User $user): bool => $user->update(['email' => 'external@example.com'])),
        default => null,
    };
    if ($user) {
        $user->teams()->attach($team);
        $this->actingAs($user);
    }

    $response = $this->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => $transfer->expires_at->toIso8601String(),
    ]);
    $response->assertStatus($actor === 'guest' ? 401 : 403);

    expect($transfer->refresh()->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
})->with(['guest', 'admin', 'member', 'unverified', 'ineligible']);

it('validates the expected expiry before making changes', function (mixed $expectedExpiry): void {
    $transfer = Transfer::factory()->create();
    $this->actingAs($transfer->user)->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => $expectedExpiry,
    ])->assertUnprocessable()->assertJsonValidationErrors('expected_expires_at');
    expect($transfer->refresh()->expires_at->equalTo(now()->addDays(7)))->toBeTrue();
})->with([null, '', 'not a date', [['nested']], 123]);

it('rejects duplicate submissions but allows intentional extensions with the latest expiry', function (): void {
    $transfer = Transfer::factory()->expired()->create();
    $payload = ['expected_expires_at' => $transfer->expires_at->toIso8601String()];
    $this->actingAs($transfer->user)->postJson('/transfers/'.$transfer->id.'/extend', $payload)->assertOk();
    $this->postJson('/transfers/'.$transfer->id.'/extend', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('expected_expires_at');
    expect($transfer->refresh()->expires_at->equalTo(now()->addDays(7)))->toBeTrue();

    $this->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => $transfer->expires_at->toIso8601String(),
    ])->assertOk();
    expect($transfer->refresh()->expires_at->equalTo(now()->addDays(14)))->toBeTrue();
});

it('preserves the link sharing files archive and statistics and leaves the toast to the page', function (): void {
    $transfer = Transfer::factory()->expired()->create([
        'visibility' => 'teams', 'download_count' => 5, 'first_opened_at' => now()->subDays(2),
        'last_downloaded_at' => now()->subDays(2), 'archive_path' => 'archives/ready.zip', 'archive_status' => 'ready',
    ]);
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $file = TransferFile::factory()->for($transfer)->create(['download_count' => 2]);
    $attributes = $transfer->getAttributes();
    $fileAttributes = $file->refresh()->getAttributes();
    unset($attributes['expires_at'], $attributes['updated_at']);
    $payload = ['expected_expires_at' => $transfer->expires_at->toIso8601String()];

    $this->actingAs($transfer->user)->from(route('transfers.show', $transfer))
        ->post('/transfers/'.$transfer->id.'/extend', $payload)
        ->assertRedirect(route('transfers.show', $transfer))->assertInertiaFlashMissing('toast');

    expect($transfer->refresh()->only(array_keys($attributes)))->toEqual($attributes)
        ->and($file->refresh()->getAttributes())->toEqual($fileAttributes)
        ->and($transfer->teams()->pluck('teams.id')->all())->toBe([$team->id]);

    $this->get(route('transfers.show', $transfer))->assertOk();
    $this->post('/transfers/'.$transfer->id.'/extend', $payload)
        ->assertSessionHasErrors('expected_expires_at')->assertInertiaFlashMissing('toast');
});

it('returns a retryable error without extending when the byte lock times out', function (): void {
    $transfer = Transfer::factory()->expired()->create();
    $expiry = $transfer->expires_at->toIso8601String();
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->with(10)->andThrow(new LockTimeoutException);
    Cache::shouldReceive('lock')->with('transfer-bytes:'.$transfer->id, 3700)->andReturn($lock);

    $this->actingAs($transfer->user)->from(route('transfers.show', $transfer))
        ->post('/transfers/'.$transfer->id.'/extend', ['expected_expires_at' => $expiry])
        ->assertRedirect(route('transfers.show', $transfer))->assertSessionHasErrors('expected_expires_at')
        ->assertInertiaFlashMissing('toast');
    expect($transfer->refresh()->expires_at->toIso8601String())->toBe($expiry);
});

it('restores the original public and restricted download links with their existing permissions', function (bool $restricted): void {
    $transfer = Transfer::factory()->expired()->create(['visibility' => $restricted ? 'teams' : 'public']);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'retained file');
    $recipient = User::factory()->create();
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $recipient->teams()->attach($team);
    $pageUrl = route('shared.show', $transfer->token);
    $downloadUrl = route('shared.files.download', [$transfer->token, $file]);
    $this->get($pageUrl)->assertGone();

    $this->actingAs($transfer->user)->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => $transfer->expires_at->toIso8601String(),
    ])->assertOk();
    auth()->forgetGuards();
    $this->flushSession();
    if ($restricted) {
        $this->get($pageUrl)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->postJson($downloadUrl)->assertForbidden();
        $this->flushSession();
        $this->actingAs($recipient);
    }

    $this->get($pageUrl)->assertOk();
    $response = $this->postJson($downloadUrl)->assertOk();
    $this->get($response->json('url'))->assertOk()->assertStreamedContent('retained file');
    if ($restricted) {
        $recipient->teams()->detach();
        $this->postJson($downloadUrl)->assertForbidden();
    }
})->with([false, true]);

it('keeps extended bytes through old cleanup jobs until the new retention boundary', function (): void {
    $transfer = Transfer::factory()->create(['expires_at' => now()->subDays(29)]);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'retained file');
    $job = new PurgeTransfer($transfer->id);
    $this->actingAs($transfer->user)->postJson('/transfers/'.$transfer->id.'/extend', [
        'expected_expires_at' => $transfer->expires_at->toIso8601String(),
    ])->assertOk();
    $newExpiry = $transfer->refresh()->expires_at;

    $this->travel(2)->days();
    $job->handle(resolve(TransferStorage::class));
    Storage::disk('local')->assertExists($file->path);
    $this->travelTo($newExpiry->copy()->addDays(30)->subSecond());
    $this->artisan('filemax:cleanup')->assertSuccessful();
    Storage::disk('local')->assertExists($file->path);
    $this->travel(1)->second();
    $this->artisan('filemax:cleanup')->assertSuccessful();
    Storage::disk('local')->assertMissing($file->path);
    expect($transfer->refresh()->purged_at)->not->toBeNull();
});
