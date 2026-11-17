<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Secret;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Notifications\OrganizationInvite;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('every user gets a Personal organization and works in it by default', function (): void {
    $user = User::factory()->create();
    $personal = $user->organizations()->sole();

    expect($personal->personal)->toBeTrue()
        ->and($personal->hasAdmin($user))->toBeTrue()
        ->and($user->currentOrganization()->is($personal))->toBeTrue();

    $this->actingAs($user)->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('auth.organizations', 1)
        ->where('auth.organizations.0', ['id' => $personal->id, 'name' => 'Personal', 'personal' => true, 'logo_url' => null, 'role' => 'admin', 'members_count' => 1, 'current' => true]));
});

test('users switch only to organizations they belong to, and fall back to Personal after leaving', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $organization->users()->attach($user);
    $foreign = Organization::factory()->create();

    $this->actingAs($user)->post(route('organizations.switch', $foreign))->assertForbidden();
    $this->from(route('transfers.index'))->post(route('organizations.switch', $organization))->assertRedirect(route('transfers.index'));
    expect($user->refresh()->currentOrganization()->is($organization))->toBeTrue();

    $this->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->where('auth.organizations.0.current', false)
        ->where('auth.organizations.1', ['id' => $organization->id, 'name' => $organization->name, 'personal' => false, 'logo_url' => null, 'role' => 'member', 'members_count' => 1, 'current' => true]));

    $organization->users()->detach($user);
    expect($user->refresh()->currentOrganization()->personal)->toBeTrue();
});

test('switching away from organization settings goes home instead of back to a forbidden page', function (): void {
    $user = User::factory()->create();
    joinOrganization($user, 'admin');

    $this->actingAs($user)->from(route('organization.members'))
        ->post(route('organizations.switch', $user->organizations()->where('personal', true)->sole()))
        ->assertRedirect(route('home'));
});

test('what a user creates belongs to the organization they are in, and lists follow it', function (): void {
    $user = User::factory()->create();
    $personalSecret = Secret::factory()->for($user)->create();
    $personalDrop = Drop::factory()->for($user)->create();
    $personal = $user->currentOrganization();
    $organization = joinOrganization($user);
    $secret = Secret::factory()->for($user)->create();
    $drop = Drop::factory()->for($user)->create();
    $received = Transfer::factory()->for($user)->received()->create();

    expect($personalSecret->organization_id)->toBe($personal->id)
        ->and($secret->organization_id)->toBe($organization->id)
        ->and($drop->organization_id)->toBe($organization->id)
        ->and($received->organization_id)->toBe($organization->id);

    $this->actingAs($user);
    $this->get(route('secrets.index'))->assertInertia(fn (Assert $page): Assert => $page->has('secrets.data', 1)->where('secrets.data.0.id', $secret->id));
    $this->get(route('drops.index'))->assertInertia(fn (Assert $page): Assert => $page->has('drops.data', 2));
    $this->get(route('received.index'))->assertInertia(fn (Assert $page): Assert => $page->has('received.data', 1));

    $this->post(route('organizations.switch', $personal));
    $this->get(route('secrets.index'))->assertInertia(fn (Assert $page): Assert => $page->has('secrets.data', 1)->where('secrets.data.0.id', $personalSecret->id));
    $this->get(route('drops.index'))->assertInertia(fn (Assert $page): Assert => $page->has('drops.data', 1)->where('drops.data.0.id', $personalDrop->id));
    $this->get(route('received.index'))->assertInertia(fn (Assert $page): Assert => $page->has('received.data', 0)->where('totals', ['total' => 0, 'new' => 0]));
});

test('a Drop submission belongs to the organization of its Drop, not the one its owner is in now', function (): void {
    $user = User::factory()->create();
    $personal = $user->currentOrganization();
    $drop = Drop::factory()->for($user)->create();
    joinOrganization($user);

    $id = $this->postJson(route('shared.drops.submissions.store', $drop->token), [
        'sender_name' => 'Client', 'files' => [['name' => 'brief.pdf', 'size' => 5, 'type' => 'application/pdf']],
    ])->assertCreated()->json('submission.id');

    expect(Transfer::query()->findOrFail($id)->organization_id)->toBe($personal->id);
});

test('recipient pages show the organization next to the sender, and nothing for Personal', function (): void {
    $user = User::factory()->create();
    $personalTransfer = Transfer::factory()->for($user)->create();
    TransferFile::factory()->for($personalTransfer)->create();
    $personalSecret = Secret::factory()->for($user)->create();
    $personalDrop = Drop::factory()->for($user)->create();
    $organization = joinOrganization($user);
    $transfer = Transfer::factory()->for($user)->create();
    TransferFile::factory()->for($transfer)->create();
    $secret = Secret::factory()->for($user)->create();
    $drop = Drop::factory()->for($user)->create();

    $this->get(route('shared.show', $transfer->token))->assertInertia(fn (Assert $page): Assert => $page->where('transfer.organization', $organization->name));
    $this->get(route('shared.secrets.show', $secret->token))->assertInertia(fn (Assert $page): Assert => $page->where('secret.organization', $organization->name));
    $this->get(route('shared.drops.show', $drop->token))->assertInertia(fn (Assert $page): Assert => $page->where('drop.organization', $organization->name));
    $this->get(route('shared.show', $personalTransfer->token))->assertInertia(fn (Assert $page): Assert => $page->where('transfer.organization', null));
    $this->get(route('shared.secrets.show', $personalSecret->token))->assertInertia(fn (Assert $page): Assert => $page->where('secret.organization', null));
    $this->get(route('shared.drops.show', $personalDrop->token))->assertInertia(fn (Assert $page): Assert => $page->where('drop.organization', null));
});

test('organization settings open only for admins of a real organization', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));

    $user = User::factory()->create();
    $this->actingAs($user)->get(route($route))->assertForbidden();

    $organization = joinOrganization($user);
    $this->get(route($route))->assertForbidden();

    $organization->users()->updateExistingPivot($user->id, ['role' => 'admin']);
    $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('organization/settings', false)->where('organization.name', $organization->name));

    $user->forceFill(['email_verified_at' => null])->save();
    $this->get(route($route))->assertRedirect(route('verification.notice'));
})->with(['organization.show', 'organization.members', 'organization.domains']);

test('members cannot change anything in organization settings', function (): void {
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');
    $member = User::factory()->create();
    joinOrganization($member, 'member', $organization);
    $invitation = OrganizationInvitation::factory()->for($organization)->create();
    $domain = $organization->domains()->create(['domain' => 'example.com']);

    $this->actingAs($member);
    $this->post(route('organization.update'), ['name' => 'Hijacked'])->assertForbidden();
    $this->patch(route('organization.members.update', $admin), ['role' => 'member'])->assertForbidden();
    $this->delete(route('organization.members.destroy', $admin))->assertForbidden();
    $this->post(route('organization.invitations.store'), ['emails' => ['friend@example.com'], 'role' => 'admin'])->assertForbidden();
    $this->post(route('organization.invitations.resend', $invitation))->assertForbidden();
    $this->delete(route('organization.invitations.destroy', $invitation))->assertForbidden();
    $this->post(route('organization.domains.store'), ['domain' => 'evil.com'])->assertForbidden();
    $this->delete(route('organization.domains.destroy', $domain))->assertForbidden();

    expect($organization->refresh()->name)->not->toBe('Hijacked')
        ->and($organization->hasAdmin($admin))->toBeTrue()
        ->and($organization->invitations()->count())->toBe(1)
        ->and($organization->domains()->count())->toBe(1);
});

test('admins rename the organization and replace or remove its logo', function (): void {
    config(['filesystems.default' => 'logos']);
    Storage::fake('logos');
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');

    $this->actingAs($admin)->post(route('organization.update'), ['name' => ''])->assertSessionHasErrors('name');
    $this->post(route('organization.update'), ['name' => 'Studio Conti', 'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
        ->assertSessionHasErrors('logo');
    $this->post(route('organization.update'), ['name' => 'Studio Conti', 'logo' => UploadedFile::fake()->image('logo.png', 400, 300)])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.title', 'Organization updated');

    $path = $organization->refresh()->logo_path;
    expect($organization->name)->toBe('Studio Conti')->and($path)->toStartWith('logos/');
    Storage::assertExists($path);

    $this->post(route('organization.update'), ['name' => 'Studio Conti', 'remove_logo' => true])->assertSessionHasNoErrors();
    expect($organization->refresh()->logo_path)->toBeNull();
    Storage::assertMissing($path);
});

test('admins change roles and remove members, but never themselves or outsiders', function (): void {
    $admin = User::factory()->create();
    $organization = joinOrganization($admin, 'admin');
    $member = User::factory()->create();
    joinOrganization($member, 'member', $organization);
    $outsider = User::factory()->create();

    $this->actingAs($admin)->get(route('organization.members'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('members', 2)->where('members', fn ($members): bool => collect($members)->pluck('role', 'id')->all() === [$admin->id => 'admin', $member->id => 'member'] || collect($members)->pluck('role', 'id')->all() === [$member->id => 'member', $admin->id => 'admin']));

    $this->patch(route('organization.members.update', $member), ['role' => 'owner'])->assertSessionHasErrors('role');
    $this->patch(route('organization.members.update', $member), ['role' => 'admin'])->assertSessionHasNoErrors();
    expect($organization->hasAdmin($member))->toBeTrue();

    $this->patch(route('organization.members.update', $admin), ['role' => 'member'])->assertForbidden();
    $this->delete(route('organization.members.destroy', $admin))->assertForbidden();
    $this->patch(route('organization.members.update', $outsider), ['role' => 'admin'])->assertNotFound();
    $this->delete(route('organization.members.destroy', $outsider))->assertNotFound();
    expect($organization->hasAdmin($admin))->toBeTrue()->and($organization->hasMember($outsider))->toBeFalse();

    $this->delete(route('organization.members.destroy', $member))->assertInertiaFlash('toast.title', 'Member removed');
    expect($organization->hasMember($member))->toBeFalse()
        ->and($member->refresh()->current_organization_id)->toBeNull()
        ->and($member->currentOrganization()->personal)->toBeTrue();
});

test('admins invite people by email, skipping members, and can resend or cancel', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['name' => 'Giulia Rossi']);
    $organization = joinOrganization($admin, 'admin');
    $member = User::factory()->create();
    joinOrganization($member, 'member', $organization);

    $this->actingAs($admin)->post(route('organization.invitations.store'), ['emails' => ['not-an-email'], 'role' => 'member'])->assertSessionHasErrors('emails.0');
    $this->post(route('organization.invitations.store'), ['emails' => [], 'role' => 'member'])->assertSessionHasErrors('emails');
    $this->post(route('organization.invitations.store'), ['emails' => ['anna@studioconti.it'], 'role' => 'owner'])->assertSessionHasErrors('role');
    Notification::assertNothingSent();

    $this->post(route('organization.invitations.store'), ['emails' => [' Anna@StudioConti.it ', 'anna@studioconti.it', $member->email], 'role' => 'admin'])
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.title', 'Invite sent');

    $invitation = $organization->invitations()->sole();
    expect($invitation->email)->toBe('anna@studioconti.it')
        ->and($invitation->role)->toBe('admin')
        ->and($invitation->invited_by)->toBe($admin->id)
        ->and($invitation->expires_at->timestamp)->toBe(now()->addDays(7)->timestamp);
    Notification::assertSentOnDemandTimes(OrganizationInvite::class, 1);
    Notification::assertSentOnDemand(OrganizationInvite::class, function (OrganizationInvite $notification, array $channels, AnonymousNotifiable $notifiable) use ($invitation, $organization): bool {
        $mail = $notification->toMail($notifiable);

        return $notifiable->routes['mail'] === 'anna@studioconti.it'
            && $mail->subject === "Giulia Rossi invited you to {$organization->name}"
            && $mail->actionUrl === route('invitations.show', $invitation);
    });

    $this->get(route('organization.members'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('invitations', 1)->where('invitations.0.email', 'anna@studioconti.it')->where('invitations.0.invited_by', 'you')->where('invitations.0.expired', false));

    $this->travel(3)->days();
    $this->post(route('organization.invitations.resend', $invitation))->assertInertiaFlash('toast.title', 'Invite sent again');
    expect($invitation->refresh()->expires_at->timestamp)->toBe(now()->addDays(7)->timestamp);
    Notification::assertSentOnDemandTimes(OrganizationInvite::class, 2);

    $foreign = OrganizationInvitation::factory()->create();
    $this->post(route('organization.invitations.resend', $foreign))->assertNotFound();
    $this->delete(route('organization.invitations.destroy', $foreign))->assertNotFound();
    $this->delete(route('organization.invitations.destroy', $invitation))->assertInertiaFlash('toast.title', 'Invite cancelled');
    expect($organization->invitations()->count())->toBe(0);
    $this->assertModelExists($foreign);
});

test('an invited person without an account registers through the invite even while sign-up is closed', function (): void {
    config(['filemax.signup_open' => false]);
    $invitation = OrganizationInvitation::factory()->create(['email' => 'anna@studioconti.it', 'role' => 'admin', 'invited_by' => User::factory()->create(['name' => 'Giulia Rossi'])->id]);
    $organization = $invitation->organization;

    $this->get(route('invitations.show', $invitation))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('invitations/show', false)
        ->where('invitation.state', 'register')->where('invitation.email', 'anna@studioconti.it')
        ->where('invitation.organization.name', $organization->name)->where('invitation.inviter', 'Giulia Rossi'));

    $this->post(route('invitations.register', $invitation), ['name' => 'Anna', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
    $this->post(route('invitations.register', $invitation), [
        'name' => 'Anna Conti', 'email' => 'someone-else@example.com', 'is_admin' => true,
        'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password',
    ])->assertRedirect(route('home'))->assertInertiaFlash('toast.title', "You joined {$organization->name}");

    $user = User::query()->where('email', 'anna@studioconti.it')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->is_admin)->toBeFalse()
        ->and($organization->hasAdmin($user))->toBeTrue()
        ->and($user->currentOrganization()->is($organization))->toBeTrue()
        ->and($user->organizations()->count())->toBe(2);
    $this->assertModelMissing($invitation);
});

test('a signed-in invitee joins with one click and other accounts cannot use the invite', function (): void {
    $invitee = User::factory()->unverified()->create(['email' => 'anna@studioconti.it']);
    $invitation = OrganizationInvitation::factory()->create(['email' => 'anna@studioconti.it']);
    $organization = $invitation->organization;
    $url = route('invitations.show', $invitation);

    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
    $this->post(route('invitations.register', $invitation), ['name' => 'Impostor', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'])->assertForbidden();
    $this->post(route('invitations.accept', $invitation))->assertRedirect(route('login'));

    $other = User::factory()->create();
    $this->actingAs($other)->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('invitation.state', 'wrong_account'));
    $this->post(route('invitations.accept', $invitation))->assertForbidden();
    expect($organization->hasMember($other))->toBeFalse();

    $this->flushSession()->actingAs($invitee)->get($url)->assertInertia(fn (Assert $page): Assert => $page->where('invitation.state', 'join'));
    $this->post(route('invitations.accept', $invitation))->assertRedirect(route('home'));
    expect($organization->hasMember($invitee))->toBeTrue()
        ->and($organization->hasAdmin($invitee))->toBeFalse()
        ->and($invitee->refresh()->hasVerifiedEmail())->toBeTrue()
        ->and($invitee->currentOrganization()->is($organization))->toBeTrue();
    $this->get($url)->assertNotFound();
});

test('an expired invite does nothing', function (): void {
    $invitation = OrganizationInvitation::factory()->expired()->create(['email' => 'anna@studioconti.it']);

    $this->get(route('invitations.show', $invitation))->assertGone()->assertInertia(fn (Assert $page): Assert => $page->where('invitation', null));
    $this->post(route('invitations.register', $invitation), ['name' => 'Anna', 'password' => 'a-secure-password', 'password_confirmation' => 'a-secure-password'])->assertGone();
    expect(User::query()->count())->toBe(0);

    $invitee = User::factory()->create(['email' => 'anna@studioconti.it']);
    $this->actingAs($invitee)->post(route('invitations.accept', $invitation))->assertGone();
    expect($invitation->organization->hasMember($invitee))->toBeFalse();
});

test('admins add and remove allowed domains', function (): void {
    $admin = User::factory()->create(['email' => 'giulia@studioconti.it']);
    $organization = joinOrganization($admin, 'admin');

    $this->actingAs($admin)->post(route('organization.domains.store'), ['domain' => 'https://studioconti.it'])->assertSessionHasErrors('domain');
    $this->post(route('organization.domains.store'), ['domain' => 'no-dot'])->assertSessionHasErrors('domain');
    $this->post(route('organization.domains.store'), ['domain' => ' @StudioConti.IT '])->assertSessionHasNoErrors();
    $this->post(route('organization.domains.store'), ['domain' => 'studioconti.it'])->assertSessionHasErrors('domain');

    $domain = $organization->domains()->sole();
    expect($domain->domain)->toBe('studioconti.it');
    $this->get(route('organization.domains'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('domains', 1)->where('domains.0.domain', 'studioconti.it')->where('domains.0.members_count', 1));

    $foreign = Organization::factory()->create()->domains()->create(['domain' => 'studioconti.it']);
    $this->delete(route('organization.domains.destroy', $foreign))->assertNotFound();
    $this->delete(route('organization.domains.destroy', $domain))->assertInertiaFlash('toast.title', 'Domain removed');
    expect($organization->domains()->count())->toBe(0)->and($organization->hasMember($admin))->toBeTrue();
    $this->assertModelExists($foreign);
});

test('the organization command creates an organization with a verified first admin', function (): void {
    $user = User::factory()->create(['email' => 'alice@mediamaxcommunication.it']);
    $unverified = User::factory()->unverified()->create();

    $this->artisan('filemax:organization', ['name' => 'Lenergy', 'email' => $unverified->email])->assertFailed();
    $this->artisan('filemax:organization', ['name' => 'Lenergy', 'email' => 'missing@example.com'])->assertFailed();
    expect(Organization::query()->where('personal', false)->count())->toBe(0);

    $this->artisan('filemax:organization', ['name' => 'Lenergy', 'email' => ' ALICE@MEDIAMAXCOMMUNICATION.IT '])->assertSuccessful();
    $organization = Organization::query()->where('name', 'Lenergy')->sole();
    expect($organization->personal)->toBeFalse()->and($organization->hasAdmin($user))->toBeTrue();
});

test('admin bootstrap requires an existing verified user and supports revocation', function (): void {
    $user = User::factory()->create(['email' => 'alice@mediamaxcommunication.it']);
    $unverified = User::factory()->unverified()->create();
    $this->artisan('filemax:admin', ['email' => $unverified->email])->assertFailed();
    $this->artisan('filemax:admin', ['email' => 'missing@mediamaxcommunication.it'])->assertFailed();
    $this->artisan('filemax:admin', ['email' => 'ALICE@MEDIAMAXCOMMUNICATION.IT'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();
    $this->artisan('filemax:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();
});

test('the migration moves everything that existed into a first organization and gives each user a Personal one', function (): void {
    $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
    expect(Schema::hasTable('organizations'))->toBeFalse()->and(Schema::hasTable('teams'))->toBeTrue();

    $now = now();
    $row = fn (array $attributes): array => ['id' => (string) Str::uuid7(), 'created_at' => $now, 'updated_at' => $now, ...$attributes];
    $admin = $row(['name' => 'Admin', 'email' => 'admin@mediamaxcommunication.it', 'password' => 'x', 'is_admin' => true]);
    $member = $row(['name' => 'Member', 'email' => 'member@mediamaxcommunication.it', 'password' => 'x', 'is_admin' => false]);
    DB::table('users')->insert([$admin, $member]);
    $team = $row(['name' => 'Creative']);
    DB::table('teams')->insert($team);
    DB::table('team_user')->insert(['team_id' => $team['id'], 'user_id' => $member['id']]);
    $restricted = $row(['user_id' => $member['id'], 'token' => Str::random(64), 'visibility' => 'teams', 'status' => 'ready', 'expires_in_days' => 7]);
    $public = $row(['user_id' => $admin['id'], 'token' => Str::random(64), 'visibility' => 'public', 'status' => 'ready', 'expires_in_days' => 7]);
    DB::table('transfers')->insert([$restricted, $public]);
    DB::table('team_transfer')->insert(['team_id' => $team['id'], 'transfer_id' => $restricted['id']]);
    DB::table('transfer_files')->insert($row(['transfer_id' => $restricted['id'], 'original_name' => 'brief.pdf', 'path' => 'transfers/brief', 'size' => 5, 'position' => 0, 'part_size' => 5]));
    DB::table('secrets')->insert($row(['user_id' => $admin['id'], 'token' => Str::random(32), 'payload' => 'x', 'verifier_hash' => 'x', 'max_views' => 1, 'expires_at' => $now]));
    DB::table('drops')->insert($row(['user_id' => $admin['id'], 'token' => Str::random(16), 'title' => 'Brief', 'closes_at' => $now, 'keep_days' => 7]));
    config(['filemax.migration' => ['organization_name' => 'Mediamax Communication', 'allowed_email_domains' => ['mediamaxcommunication.it']]]);

    $this->artisan('migrate')->assertSuccessful();

    $organization = Organization::query()->where('personal', false)->sole();
    $admin = User::query()->findOrFail($admin['id']);
    $member = User::query()->findOrFail($member['id']);
    expect($organization->name)->toBe('Mediamax Communication')
        ->and($organization->domains()->pluck('domain')->all())->toBe(['mediamaxcommunication.it'])
        ->and($organization->hasAdmin($admin))->toBeTrue()
        ->and($organization->hasMember($member))->toBeTrue()
        ->and($organization->hasAdmin($member))->toBeFalse()
        ->and($member->currentOrganization()->is($organization))->toBeTrue()
        ->and($member->organizations()->where('personal', true)->count())->toBe(1)
        ->and(Organization::query()->where('personal', true)->count())->toBe(2)
        ->and(Transfer::query()->findOrFail($restricted['id'])->only(['visibility', 'organization_id']))->toBe(['visibility' => 'members', 'organization_id' => $organization->id])
        ->and(Transfer::query()->findOrFail($public['id'])->only(['visibility', 'organization_id']))->toBe(['visibility' => 'public', 'organization_id' => $organization->id])
        ->and(Secret::query()->sole()->organization_id)->toBe($organization->id)
        ->and(Drop::query()->sole()->organization_id)->toBe($organization->id)
        ->and(TransferFile::query()->sole()->transfer_id)->toBe($restricted['id'])
        ->and(Schema::hasTable('teams'))->toBeFalse();

    expect(fn () => $this->artisan('migrate:rollback', ['--step' => 1])->run())->toThrow(RuntimeException::class);
});
