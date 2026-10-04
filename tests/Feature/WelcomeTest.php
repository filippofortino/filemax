<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
});

test('a new account opens the intro once before its first transfer', function (): void {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)->get(route('home'))->assertRedirect(route('welcome'));
    expect($user->refresh()->onboarded_at)->not->toBeNull();

    $this->get(route('home'))->assertOk();
    $this->get(route('welcome'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('welcome'));
});

test('the intro requires a verified account', function (): void {
    $this->get(route('welcome'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('welcome'))->assertRedirect(route('verification.notice'));
});
