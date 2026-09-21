<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->withoutVite();
});

it('records the first public page opening without counting a download or exposing storage paths', function (): void {
    $transfer = Transfer::factory()->create(['title' => null]);
    $file = TransferFile::factory()->for($transfer)->create();

    $this->get(route('shared.show', $transfer->token))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/show', false)
        ->where('transfer.title', $file->original_name)
        ->where('transfer.files.0.name', $file->original_name)
        ->missing('transfer.files.0.path')
        ->missing('transfer.download_count'));
    $firstOpened = $transfer->refresh()->first_opened_at;
    $this->travel(5)->minutes();
    $this->get(route('shared.show', $transfer->token))->assertOk();

    expect($transfer->refresh()->download_count)->toBe(0)
        ->and($file->refresh()->download_count)->toBe(0)
        ->and($transfer->first_opened_at->equalTo($firstOpened))->toBeTrue();
});

it('counts every authorized individual action exactly once and never counts byte delivery', function (): void {
    $transfer = Transfer::factory()->create();
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => '../../unsafe.txt']);
    Storage::disk('local')->put($file->path, 'test');

    $url = $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk()->json('url');
    $this->get($url)->assertOk()->assertDownload('unsafe.txt')->assertStreamedContent('test');
    $this->get($url)->assertOk();
    $this->head($url)->assertOk();
    $this->actingAs($transfer->user)->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk();

    expect($transfer->refresh()->download_count)->toBe(2)
        ->and($transfer->last_downloaded_at)->not->toBeNull()
        ->and($file->refresh()->download_count)->toBe(2);
});

it('redirects restricted guests back through login and keeps denied props private', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams', 'message' => 'secret message']);
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => 'secret.pdf']);
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $url = route('shared.show', $transfer->token);

    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
    $user = User::factory()->create();
    $ownTeam = Team::factory()->create();
    $user->teams()->attach($ownTeam);
    $this->actingAs($user)->get($url)->assertForbidden()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/denied', false)
        ->where('sender.email', $transfer->user->email)
        ->where('own_teams.0.name', $ownTeam->name)
        ->missing('transfer')
        ->missing('files')
        ->missing('message'));
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertForbidden();
    expect($transfer->refresh()->first_opened_at)->toBeNull()->and($transfer->download_count)->toBe(0);
});

it('requires verified eligibility and current membership while allowing any selected team', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $file = TransferFile::factory()->for($transfer)->create();
    $teams = Team::factory()->count(2)->create();
    $transfer->teams()->attach($teams->modelKeys());
    $recipient = User::factory()->create();
    $recipient->teams()->attach($teams->modelKeys());
    $route = route('shared.files.download', [$transfer->token, $file]);

    $this->actingAs($recipient)->postJson($route)->assertOk();
    expect($transfer->refresh()->download_count)->toBe(1);
    $recipient->teams()->detach($teams[0]);
    $this->postJson($route)->assertOk();
    $recipient->teams()->detach();
    $this->postJson($route)->assertForbidden();
    $recipient->teams()->attach($teams[0]);
    $recipient->forceFill(['email_verified_at' => null])->save();
    $this->postJson($route)->assertForbidden();
    $recipient->forceFill(['email_verified_at' => now(), 'email' => 'person@example.com'])->save();
    $this->postJson($route)->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(2);
});

it('blocks expiry, deletion, missing tokens and foreign file ids without counting them', function (): void {
    $transfer = Transfer::factory()->create();
    $file = TransferFile::factory()->for($transfer)->create();
    $foreign = TransferFile::factory()->create();
    Storage::disk('local')->put($file->path, 'test');

    $this->postJson(route('shared.files.download', [$transfer->token, $foreign]))->assertNotFound();
    $this->get(route('shared.show', 'missing'))->assertNotFound();
    $transfer->update(['expires_at' => now()]);
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertGone();
    $this->get(route('shared.show', $transfer->token))->assertGone();
    Storage::disk('local')->assertExists($file->path);
    $transfer->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertGone();
    expect($transfer->refresh()->download_count)->toBe(0);
});

it('caps signed file links at transfer expiry and rechecks membership on local retrieval', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams', 'expires_at' => now()->addSeconds(30)]);
    $file = TransferFile::factory()->for($transfer)->create();
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $user = User::factory()->create();
    $user->teams()->attach($team);
    $url = $this->actingAs($user)->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe($transfer->expires_at->timestamp);
    $user->teams()->detach();
    $this->get($url)->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(1);
});
