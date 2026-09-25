<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('registers an eligible account and asks the user to verify their email', function (): void {
    Notification::fake();

    $page = visit('/register')->resize(1280, 940)
        ->assertSee('At least 8 characters.')
        ->assertAttribute('#password', 'minlength', '8')
        ->fill('name', 'Alice Test')
        ->fill('email', 'alice@mediamaxcommunication.it')
        ->fill('password', 'a-good-password')
        ->fill('password_confirmation', 'a-good-password')
        ->press('Create account')
        ->assertSee('Check your email')
        ->assertSee('alice@mediamaxcommunication.it')
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
    $user = User::factory()->create();
    Notification::fake();

    visit('/forgot-password')
        ->fill('email', $user->email)
        ->press('Send reset link')
        ->assertSee('If that account exists, a password reset link has been sent.')
        ->assertNoJavascriptErrors();

    $notification = Notification::sent($user, ResetPassword::class)->sole();

    visit(route('password.reset', ['token' => $notification->token, 'email' => $user->email]))
        ->fill('password', 'new-browser-password')
        ->fill('password_confirmation', 'new-browser-password')
        ->press('Reset password')
        ->assertSee('Forgot password?')
        ->assertNoJavascriptErrors();

    expect(Hash::check('new-browser-password', $user->refresh()->password))->toBeTrue();
});

it('signs in and signs out through the account menu', function (): void {
    $user = User::factory()->create();

    $page = visit('/login')->resize(1280, 940);
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'auth-login');

    $page
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('form button[data-slot="button"]')
        ->assertSee('Transfer details')
        ->click('[aria-label="Account menu"]')
        ->click('Sign out')
        ->assertSee('Forgot password?')
        ->assertNoJavascriptErrors();
});

it('shortens a long email in the account menu and keeps the full one on hover', function (): void {
    $user = User::factory()->create(['email' => 'filippo.fortino@mediamaxcommunication.it']);
    $this->actingAs($user);
    $email = '[data-slot="popover-content"] [title]';

    $page = visit('/')->resize(1280, 940)
        ->click('[aria-label="Account menu"]')
        ->assertAttribute($email, 'title', $user->email);
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'account-menu-long-email');

    expect($page->script("() => { const email = document.querySelector('{$email}'); return email.scrollWidth > email.clientWidth; }"))->toBeTrue()
        ->and($page->script('() => document.querySelector(\'[data-slot="popover-content"]\').offsetWidth'))->toBe(216)
        ->and($page->script("() => { const email = document.querySelector('{$email}'); const name = email.previousElementSibling; return email.offsetTop - name.offsetTop - name.offsetHeight; }"))->toBe(2);
    $page->assertNoJavascriptErrors();
});

it('lets an admin add and remove a registered member in the teams interface', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create(['name' => 'Beatrice Test']);
    $team = Team::factory()->create(['name' => 'Creative Studio']);
    $this->actingAs($admin);

    $page = visit('/teams')->resize(1280, 940)
        ->assertSee('Creative Studio')
        ->select('user_id', $member->id)
        ->press('Add member')
        ->assertSee('1 member');

    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'teams-admin');

    $page
        ->press('[aria-label="Remove Beatrice Test from Creative Studio"]')
        ->assertSee('This team has no members yet.')
        ->assertNoJavascriptErrors();

    expect($team->users()->count())->toBe(0);

    $page->fill('#name-'.$team->id, 'Creative Studio Updated')
        ->press('Rename')
        ->assertSee('Team renamed.')
        ->fill('#team-name', 'Production')
        ->press('Create team')
        ->assertSee('Team created.')
        ->assertSee('Production')
        ->assertNoJavascriptErrors();

    expect($team->refresh()->name)->toBe('Creative Studio Updated');
    expect(Team::query()->where('name', 'Production')->exists())->toBeTrue();
});
