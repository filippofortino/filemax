<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('permits current members of the organization but never admin bypass', function (): void {
    $transfer = Transfer::factory()->membersOnly()->create();
    $recipient = User::factory()->create();
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeFalse();
    $transfer->organization->users()->attach($recipient);
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeTrue();
    $transfer->organization->users()->detach($recipient);
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeFalse();
    Organization::factory()->create()->users()->attach($recipient);
    expect(Gate::forUser($recipient)->allows('download', $transfer))->toBeFalse();
    expect(Gate::forUser(User::factory()->admin()->create())->allows('download', $transfer))->toBeFalse();
});

it('requires verified members and never opens Drop submissions to them', function (): void {
    $transfer = Transfer::factory()->membersOnly()->create();
    $unverified = User::factory()->unverified()->create();
    $transfer->organization->users()->attach($unverified);
    expect(Gate::forUser($unverified)->allows('download', $transfer))->toBeFalse();
    expect(Gate::forUser(null)->allows('download', $transfer))->toBeFalse();

    $member = User::factory()->create();
    $transfer->organization->users()->attach($member);
    $transfer->update(['visibility' => 'drop']);
    expect(Gate::forUser($member)->allows('download', $transfer))->toBeFalse();
});

it('allows the owner after leaving the organization but still checks expiry and revocation', function (): void {
    $transfer = Transfer::factory()->membersOnly()->create();
    $transfer->organization->users()->detach($transfer->user);
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
