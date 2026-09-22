<?php

declare(strict_types=1);

use App\Models\User;

it('shows the sign in form to guests', function (): void {
    $page = visit('/')->resize(1280, 940)
        ->assertSee('Sign in')->assertSee('Create an account')->assertNoJavascriptErrors();
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'auth-login-desktop');

    $page->resize(390, 844)->keys('#email', 'Shift+Tab')->keys(':focus', 'Tab');

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($page->script(<<<'JS'
        () => {
            const input = document.querySelector('#email');
            const style = getComputedStyle(input);
            return document.activeElement === input && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0;
        }
        JS))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'auth-login-phone');
});

it('shows the new transfer form to verified staff', function (): void {
    $this->actingAs(User::factory()->create());
    visit('/')->assertSee('Drop files here')->assertSee('Transfer details')
        ->assertSee('7 days')->assertDontSee('Once downloaded')->assertNoJavascriptErrors();
});
