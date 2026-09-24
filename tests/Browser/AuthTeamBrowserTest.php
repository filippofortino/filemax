<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
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
});
