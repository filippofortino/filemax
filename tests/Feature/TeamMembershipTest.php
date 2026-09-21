<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('administrators see team members and only eligible registered choices', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    User::factory()->create(['email' => 'outside@example.com']);
    $team = Team::factory()->create();
    $team->users()->attach($member);

    $this->actingAs($admin)->get(route('teams.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('teams/index')
        ->has('teams', 1)
        ->where('teams.0.users_count', 1)
        ->where('teams.0.users.0.id', $member->id)
        ->has('users', 2));
});

test('verified admins can create rename and manage multiple memberships', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $other = Team::factory()->create();
    $this->actingAs($admin)->post(route('teams.store'), ['name' => 'Design'])->assertRedirect(route('teams.index'));
    $team = Team::query()->where('name', 'Design')->sole();
    $this->patch(route('teams.update', $team), ['name' => 'Creative'])->assertRedirect();
    expect($team->refresh()->name)->toBe('Creative');

    $this->post(route('teams.members.store', $team), ['user_id' => $member->id])->assertRedirect();
    $this->post(route('teams.members.store', $team), ['user_id' => $member->id])->assertRedirect();
    $this->post(route('teams.members.store', $other), ['user_id' => $member->id])->assertRedirect();
    expect($member->teams()->count())->toBe(2)->and($team->users()->count())->toBe(1);

    $this->delete(route('teams.members.destroy', [$team, $member]))->assertRedirect();
    expect($member->teams()->pluck('teams.id')->all())->toBe([$other->id]);
});

test('regular users cannot administer teams or self assign membership', function (): void {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $this->actingAs($user)->get(route('teams.index'))->assertForbidden();
    $this->post(route('teams.store'), ['name' => 'Self made'])->assertForbidden();
    $this->patch(route('teams.update', $team), ['name' => 'Changed'])->assertForbidden();
    $this->post(route('teams.members.store', $team), ['user_id' => $user->id])->assertForbidden();
    $this->delete(route('teams.members.destroy', [$team, $user]))->assertForbidden();
    expect($team->refresh()->name)->not->toBe('Changed')->and($user->teams()->count())->toBe(0);
});

test('administration requires verification and current domain eligibility', function (): void {
    $this->get(route('teams.index'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->admin()->unverified()->create())->post(route('teams.store'), ['name' => 'Design'])->assertRedirect(route('verification.notice'));
    $this->actingAs(User::factory()->admin()->create(['email' => 'former@example.com']))->post(route('teams.store'), ['name' => 'Design'])->assertForbidden();
});

test('membership rejects missing and ineligible users', function (): void {
    $admin = User::factory()->admin()->create();
    $ineligible = User::factory()->create(['email' => 'person@example.com']);
    $team = Team::factory()->create();
    $this->actingAs($admin)->post(route('teams.members.store', $team), ['user_id' => $ineligible->id])->assertSessionHasErrors('user_id');
    $this->post(route('teams.members.store', $team), ['user_id' => '00000000-0000-4000-8000-000000000000'])->assertSessionHasErrors('user_id');
    expect($team->users()->count())->toBe(0);
});

test('team names are required and cannot exceed the storage length', function (): void {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin)->post(route('teams.store'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('teams.store'), ['name' => str_repeat('a', 256)])->assertSessionHasErrors('name');
});

test('admin bootstrap requires an existing verified eligible user and supports revocation', function (): void {
    $user = User::factory()->create(['email' => 'alice@mediamaxcommunication.it']);
    $unverified = User::factory()->unverified()->create();
    $this->artisan('filemax:admin', ['email' => $unverified->email])->assertFailed();
    $this->artisan('filemax:admin', ['email' => 'missing@mediamaxcommunication.it'])->assertFailed();
    $this->artisan('filemax:admin', ['email' => 'ALICE@MEDIAMAXCOMMUNICATION.IT'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();
    $this->artisan('filemax:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();
});
