<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('permits any current selected team membership but never admin bypass', function (): void {
    $teams = Team::factory()->count(2)->create();
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $transfer->teams()->attach($teams);
    $recipient = User::factory()->create();
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeFalse();
    $recipient->teams()->attach($teams[1]);
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeTrue();
    $recipient->teams()->attach($teams[0]);
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeTrue();
    $recipient->teams()->detach();
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('download', $transfer))->toBeFalse();
});

it('requires verified eligible restricted recipients and keeps empty shares private', function (): void {
    $team = Team::factory()->create();
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $transfer->teams()->attach($team);
    $unverified = User::factory()->unverified()->create();
    $external = User::factory()->create(['email' => 'external@example.com']);
    $unverified->teams()->attach($team);
    $external->teams()->attach($team);
    expect(Gate::forUser($unverified)->allows('download', $transfer))->toBeFalse();
    expect(Gate::forUser($external)->allows('download', $transfer))->toBeFalse();
    expect(Gate::forUser(null)->allows('download', $transfer))->toBeFalse();
    $transfer->teams()->detach();
    expect(Gate::forUser(User::factory()->create())->allows('download', $transfer))->toBeFalse();
});

it('allows an eligible owner after leaving teams but still checks expiry and revocation', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $gate = Gate::forUser($transfer->user);
    expect($gate->allows('download', $transfer))->toBeTrue();
    $transfer->update(['expires_at' => now()]);
    expect($gate->allows('download', $transfer))->toBeFalse();
    expect($gate->allows('view', $transfer))->toBeTrue();

    $transfer->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
    expect($gate->allows('download', $transfer))->toBeFalse();
});

it('allows anonymous public recipients only after publication and before expiry', function (): void {
    $transfer = Transfer::factory()->uploading()->create();
    expect(Gate::forUser(null)->allows('download', $transfer))->toBeFalse();
    $transfer->update(['status' => 'ready', 'expires_at' => now()->addSecond()]);
    expect(Gate::forUser(null)->allows('download', $transfer))->toBeTrue();
    $this->travel(1)->seconds();
    expect(Gate::forUser(null)->allows('download', $transfer))->toBeFalse();
});
