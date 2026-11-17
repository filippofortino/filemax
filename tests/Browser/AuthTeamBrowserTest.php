<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvite;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('registers an eligible account and asks the user to verify their email', function (): void {
    Notification::fake();

    $page = visit('/register')->resize(1280, 940)
        ->assertSee('Use your email to get started with Filemax.')
        ->assertDontSee('Company email')
        ->assertAttribute('#email', 'placeholder', 'you@example.com')
        ->assertSee('At least 8 characters.')
        ->assertAttribute('#password', 'minlength', '8')
        ->fill('name', 'Alice Test')
        ->fill('email', 'alice@example.com')
        ->fill('password', 'a-good-password')
        ->fill('password_confirmation', 'a-good-password')
        ->press('Create account')
        ->assertSee('Check your email')
        ->assertSee('alice@example.com')
        ->assertNoJavascriptErrors();

    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'auth-verification');

    $user = User::query()->sole();
    expect($user->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user, VerifyEmail::class);

    $page->press('Resend verification email')->assertSee('A new verification link has been sent')->assertNoJavascriptErrors();
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);
});

it('requests and resets a password by clicking the form buttons', function (): void {
    $user = User::factory()->create(['email' => 'alice@example.com']);
    Notification::fake();

    visit('/forgot-password')
        ->assertSee('Enter your email and we’ll send you a link to reset your password.')
        ->assertDontSee('Company email')
        ->assertAttribute('#email', 'placeholder', 'you@example.com')
        ->fill('email', $user->email)
        ->press('Send reset link')
        ->assertSee('If that account exists, a password reset link has been sent.')
        ->assertNoJavascriptErrors();

    $notification = Notification::sent($user, ResetPassword::class)->sole();

    visit(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
        ->assertDontSee('Company email')
        ->fill('password', 'new-browser-password')
        ->fill('password_confirmation', 'new-browser-password')
        ->press('Reset password')
        ->assertSee('Forgot password?')
        ->assertNoJavascriptErrors();

    expect(Hash::check('new-browser-password', $user->refresh()->password))->toBeTrue();
});

it('signs in and signs out through the account menu', function (): void {
    $user = User::factory()->create(['remember_token' => null]);

    $page = visit('/login')->resize(1280, 940);
    $page->assertAttribute('#email', 'placeholder', 'you@example.com');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'auth-login');

    $page
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->check('remember')
        ->press('form button[data-slot="button"]')
        ->assertSee('Transfer details')
        ->click('[aria-label$="account menu"]')
        ->click('Sign out')
        ->assertSee('Forgot password?')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->remember_token)->not->toBeNull();
});

it('signs in when pressing near the edge of the submit button', function (string $reducedMotion): void {
    $user = User::factory()->create();

    $page = visit('/login', ['reducedMotion' => $reducedMotion])
        ->fill('email', $user->email)
        ->fill('password', 'password');

    $page->page()->locator('form button[data-slot="button"]')->click([
        'position' => ['x' => 1, 'y' => 24],
        'delay' => 100,
        'force' => true,
    ]);

    $page->assertSee('Transfer details')->assertNoJavascriptErrors();
})->with(['no-preference', 'reduce']);

it('shortens a long email in the account menu and keeps the full one on hover', function (): void {
    $user = User::factory()->create(['email' => 'filippo.fortino@mediamaxcommunication.it']);
    $this->actingAs($user);
    $email = '[data-slot="popover-content"] [title]';

    $page = visit('/')->resize(1280, 940)
        ->click('[aria-label$="account menu"]')
        ->assertAttribute($email, 'title', $user->email);
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'account-menu-long-email');

    expect($page->script("() => { const email = document.querySelector('{$email}'); return email.scrollWidth > email.clientWidth; }"))->toBeTrue()
        ->and($page->script('() => document.querySelector(\'[data-slot="popover-content"]\').offsetWidth'))->toBe(216)
        ->and($page->script("() => { const email = document.querySelector('{$email}'); const name = email.previousElementSibling; return email.offsetTop - name.offsetTop - name.offsetHeight; }"))->toBe(2);
    $page->assertNoJavascriptErrors();
});

it('lets an admin manage members, invites, domains and the name in organization settings', function (): void {
    Notification::fake();
    $admin = User::factory()->create(['name' => 'Giulia Rossi']);
    $organization = joinOrganization($admin, 'admin', Organization::factory()->create(['name' => 'Creative Studio']));
    $member = User::factory()->create(['name' => 'Beatrice Test']);
    $organization->users()->attach($member);
    $this->actingAs($admin);

    $page = visit('/organization/members')->resize(1280, 1180)
        ->assertSee('Creative Studio')
        ->assertSee('2 members. They can open members-only links and send as Creative Studio.')
        ->assertSee('Admin · you')
        ->assertSee('No pending invites.')
        ->select('[aria-label="Role of Beatrice Test"]', 'admin')
        ->assertSee('Role updated');
    expect($organization->hasAdmin($member))->toBeTrue();

    $page->press('Invite people')
        ->assertSee('Invite people to Creative Studio')
        ->assertButtonDisabled('Send invite')
        ->fill('#invite-emails', "anna@studioconti.it,\nluca@gmail.com")
        ->screenshot(fullPage: false, filename: 'organization-invite-dialog')
        ->press('Send 2 invites')
        ->assertSee('2 invites sent')
        ->assertSee('anna@studioconti.it')
        ->assertSee('Member · invited by you · expires');
    Notification::assertSentOnDemandTimes(OrganizationInvite::class, 2);

    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'organization-members');

    $page->press('[aria-label="Cancel invite to luca@gmail.com"]')
        ->assertSee('Invite cancelled')
        ->assertDontSee('luca@gmail.com has')
        ->press('[aria-label="Remove Beatrice Test from Creative Studio"]')
        ->assertSee('Remove Beatrice Test from Creative Studio?')
        ->press('Remove member')
        ->assertSee('Member removed')
        ->assertSee('1 member. They can open')
        ->assertNoJavascriptErrors();
    expect($organization->hasMember($member))->toBeFalse()->and($organization->invitations()->count())->toBe(1);

    $page->click('nav[aria-label="Organization settings"] a:has-text("Allowed domains")')
        ->assertSee('Anyone who signs up and verifies an email on one of these domains joins Creative Studio as a member, with no invite.')
        ->fill('domain', 'not a domain')
        ->press('Add domain')
        ->assertSee('Enter a domain like example.com, without @ or https://.')
        ->fill('domain', 'StudioConti.it')
        ->press('Add domain')
        ->assertSee('Domain added')
        ->assertSee('No member uses this domain yet')
        ->screenshot(fullPage: false, filename: 'organization-domains')
        ->press('[aria-label="Remove studioconti.it"]')
        ->assertSee('Domain removed')
        ->assertNoJavascriptErrors();
    expect($organization->domains()->count())->toBe(0);

    $page->click('nav[aria-label="Organization settings"] a:has-text("General")')
        ->assertSee('What recipients see')
        ->assertSee('Giulia Rossi · Creative Studio')
        ->fill('#org-name', 'Creative Studio Updated')
        ->assertSee('Giulia Rossi · Creative Studio Updated')
        ->screenshot(fullPage: false, filename: 'organization-general')
        ->press('Save changes')
        ->assertSee('Organization updated')
        ->assertSeeIn('button[aria-label^="Organization:"]', 'Creative Studio Updated')
        ->assertNoJavascriptErrors();
    expect($organization->refresh()->name)->toBe('Creative Studio Updated');
});

it('switches organization from the header and keeps Personal to public links', function (): void {
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $organization = joinOrganization($user, 'member', Organization::factory()->create(['name' => 'Mediamax']));
    $this->actingAs($user);

    $page = visit('/')->resize(1280, 940)
        ->assertSeeIn('button[aria-label="Organization: Mediamax. Switch organization"]', 'Mediamax')
        ->assertSee('Who can download')
        ->assertSee('Members only')
        ->assertSee('Signed-in members of Mediamax.')
        ->click('button[aria-label="Organization: Mediamax. Switch organization"]')
        ->assertSee('Send and see links as')
        ->assertSee('Only you')
        ->assertSee('Member · 1 member')
        ->assertDontSee('Mediamax settings');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'organization-switcher')
        ->click('[data-slot="popover-content"] button:has-text("Personal")')
        ->assertSeeIn('button[aria-label="Organization: Personal. Switch organization"]', 'Personal')
        ->assertSee('Anyone with the link can download. It shows your name, with no organization.')
        ->assertDontSee('Members only')
        ->assertNoJavascriptErrors();
    expect($user->refresh()->currentOrganization()->personal)->toBeTrue();

    $page = visit('/')->resize(390, 844)
        ->click('button[aria-label="Organization: Personal. Switch organization"]')
        ->assertSee('Send and see links as')
        ->screenshot(fullPage: false, filename: 'organization-switcher-phone');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('[data-slot="popover-content"] button:has-text("Mediamax")')
        ->assertVisible('button[aria-label="Organization: Mediamax. Switch organization"]')
        ->assertNoJavascriptErrors();
    expect($user->refresh()->currentOrganization()->is($organization))->toBeTrue();
});

it('keeps sign-up closed to everyone but people on an allowed domain', function (): void {
    config(['filemax.signup_open' => false]);
    Notification::fake();
    Organization::factory()->create()->domains()->create(['domain' => 'studioconti.it']);

    $page = visit('/register')->resize(1280, 940)
        ->assertSee('Sign-up is by invitation for now')
        ->assertMissing('#password')
        ->fill('email', 'luca@gmail.com')
        ->press('Continue')
        ->assertSee('No organization on Filemax uses gmail.com. Ask an admin of yours to invite you.')
        ->screenshot(fullPage: false, filename: 'signup-closed')
        ->fill('email', 'anna@studioconti.it')
        ->press('Continue')
        ->assertSee('Your email belongs to an organization on Filemax. Create your account to join it.')
        ->assertValue('#email', 'anna@studioconti.it')
        ->fill('name', 'Anna Conti')
        ->fill('password', 'a-good-password')
        ->fill('password_confirmation', 'a-good-password')
        ->press('Create account')
        ->assertSee('Verify your email')
        ->assertNoJavascriptErrors();

    expect(User::query()->where('email', 'anna@studioconti.it')->exists())->toBeTrue();
});

it('lets an invited person create an account and join, and a signed-in invitee join with one click', function (): void {
    config(['filemax.signup_open' => false]);
    $inviter = User::factory()->create(['name' => 'Giulia Rossi']);
    $organization = Organization::factory()->create(['name' => 'Mediamax Communication']);
    $invitation = OrganizationInvitation::factory()->for($organization)->create(['email' => 'anna@studioconti.it', 'invited_by' => $inviter->id]);

    $page = visit(route('invitations.show', $invitation))->resize(1280, 940)
        ->assertSee('Join Mediamax Communication')
        ->assertSee('Giulia Rossi invited you. Create your account to join.')
        ->assertValue('#email', 'anna@studioconti.it')
        ->assertAttribute('#email', 'readonly', '');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'organization-accept-invite-new')
        ->fill('name', 'Anna Conti')
        ->fill('password', 'a-good-password')
        ->fill('password_confirmation', 'a-good-password')
        ->press('Create account and join')
        ->assertSee('You joined Mediamax Communication')
        ->assertNoJavascriptErrors();

    $anna = User::query()->where('email', 'anna@studioconti.it')->sole();
    expect($organization->hasMember($anna))->toBeTrue()->and($anna->currentOrganization()->is($organization))->toBeTrue();

    $marta = User::factory()->create(['name' => 'Marta Bianchi', 'email' => 'marta@studiobassi.it']);
    $second = OrganizationInvitation::factory()->for($organization)->create(['email' => $marta->email, 'invited_by' => $inviter->id]);
    $this->flushSession()->actingAs($marta);
    visit(route('invitations.show', $second))->resize(1280, 940)
        ->assertSee('Join Mediamax Communication?')
        ->assertSee('Giulia Rossi invited you as a member. You’re signed in as marta@studiobassi.it.')
        ->screenshot(fullPage: false, filename: 'organization-accept-invite-signed-in')
        ->press('Join Mediamax Communication')
        ->assertSee('You joined Mediamax Communication')
        ->assertNoJavascriptErrors();
    expect($organization->hasMember($marta))->toBeTrue();

    visit(route('invitations.show', $second))
        ->assertSee('This invite no longer works')
        ->assertNoJavascriptErrors();
});

it('associates sign in errors and focuses the first invalid field', function (): void {
    $user = User::factory()->create();
    $page = visit('/login');

    $page->assertScript('document.getElementById("email").hasAttribute("aria-describedby")', false)
        ->assertScript('document.getElementById("password").hasAttribute("aria-describedby")', false)
        ->fill('email', $user->email)
        ->fill('password', 'incorrect-password')
        ->press('form button[data-slot="button"]')
        ->assertSee(__('auth.failed'))
        ->assertAttribute('#email', 'aria-invalid', 'true')
        ->assertAttribute('#email', 'aria-describedby', 'login-email-error')
        ->assertSeeIn('#login-email-error', __('auth.failed'))
        ->assertScript('document.activeElement.id', 'email');
    $page->screenshot(filename: 'auth-login-validation');

    $page->fill('password', '');
    $page->script('() => { document.querySelector("form").noValidate = true; }');
    $page->press('form button[data-slot="button"]')
        ->assertAttribute('#password', 'aria-invalid', 'true')
        ->assertAttribute('#password', 'aria-describedby', 'login-password-error')
        ->assertSeeIn('#login-password-error', __('validation.required', ['attribute' => 'password']))
        ->assertAttribute('#email', 'aria-invalid', 'false')
        ->assertScript('document.getElementById("email").hasAttribute("aria-describedby")', false)
        ->assertScript('document.activeElement.id', 'password');

    $page->fill('password', 'password')
        ->press('form button[data-slot="button"]')
        ->assertSee('Transfer details')
        ->assertNoJavascriptErrors();
});
