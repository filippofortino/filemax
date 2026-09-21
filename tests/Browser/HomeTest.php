<?php

declare(strict_types=1);

use App\Models\User;

it('shows the sign in form to guests', function (): void {
    visit('/')->assertSee('Sign in')->assertSee('Create an account')->assertNoJavascriptErrors();
});

it('welcomes verified staff', function (): void {
    $this->actingAs(User::factory()->create());
    visit('/')->assertSee('Welcome')->assertNoJavascriptErrors();
});
