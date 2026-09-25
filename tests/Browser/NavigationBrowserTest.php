<?php

declare(strict_types=1);

use App\Models\User;

it('keeps the main navigation in place and offers a new transfer button outside the new transfer page', function (): void {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Filippo Fortino']));
    $navigationLeft = '() => document.querySelector(\'nav[aria-label="Main navigation"]\').getBoundingClientRect().left';

    $page = visit('/')->resize(1280, 940)
        ->assertSee('Drop files here')
        ->assertMissing('header a[aria-label="New transfer"]');
    $page->script('() => document.fonts.ready');
    $left = $page->script($navigationLeft);

    $page->click('nav a:has-text("My transfers")')
        ->assertSee('No transfers yet')
        ->assertVisible('header a[aria-label="New transfer"]');
    expect($page->script($navigationLeft))->toEqualWithDelta($left, 1);

    $page->click('nav a:has-text("Teams")')
        ->assertSee('Manage who can receive transfers')
        ->assertVisible('header a[aria-label="New transfer"]');
    expect($page->script($navigationLeft))->toEqualWithDelta($left, 1);

    $page->click('header a[aria-label="New transfer"]')
        ->assertSee('Drop files here')
        ->assertNoJavascriptErrors();
});
