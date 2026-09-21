<?php

declare(strict_types=1);

use App\Models\User;

it('shows the sign in form to guests', function (): void {
    visit('/')->assertSee('Sign in')->assertSee('Create an account')->assertNoJavascriptErrors();
});

it('shows the new transfer form to verified staff', function (): void {
    $this->actingAs(User::factory()->create());
    visit('/')->assertSee('Drop files here')->assertSee('Transfer details')
        ->assertSee('7 days')->assertDontSee('Once downloaded')->assertNoJavascriptErrors();
});
