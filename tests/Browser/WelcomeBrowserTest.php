<?php

declare(strict_types=1);

use App\Models\User;

it('walks a new account through the intro and into its first transfer', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Filippo Fortino', 'onboarded_at' => null]));

    $page = visit('/')->resize(1280, 940)
        ->assertPathIs('/welcome')
        ->assertSee('Step 1 of 6')
        ->assertSee('Welcome to Filemax, Filippo')
        ->assertSee('https://filemax.dev/t/8kq2vx')
        ->assertMissing('button:has-text("Back")')
        ->assertNoJavascriptErrors();
    $page->script('() => document.fonts.ready');
    $page->wait(0.4)->screenshot(fullPage: false, filename: 'welcome-1');

    foreach ([2 => 'Drop files, get one link', 3 => 'Choose who can download', 4 => 'Pick how long it lasts', 5 => 'Share a secret that opens once', 6 => 'Ask for files with a Drop'] as $step => $title) {
        $page->press('Next')->assertSee("Step {$step} of 6")->assertSee($title);
        expect($page->script('() => document.activeElement.id'))->toBe('step-title');
        $page->wait(0.4)->screenshot(fullPage: false, filename: "welcome-{$step}");
    }

    $page->assertDontSee('Skip intro')->assertDontSee('Once downloaded')
        ->press('Back')->assertSee('Step 5 of 6')
        ->press('Next')->click('Create your first transfer')
        ->assertSee('Drop files here')
        ->assertNoJavascriptErrors();
});

it('lets a new account skip the intro', function (): void {
    $this->actingAs(User::factory()->create(['onboarded_at' => null]));

    visit('/')->assertSee('Step 1 of 6')
        ->click('Skip intro')
        ->assertSee('Drop files here')
        ->assertNoJavascriptErrors();
});

it('fits every intro step on a phone', function (int $width): void {
    $this->actingAs(User::factory()->create());
    $fits = '() => document.documentElement.scrollWidth <= innerWidth';

    $page = visit('/welcome')->resize($width, 844)->assertSee('Step 1 of 6');
    expect($page->script($fits))->toBeTrue();

    foreach (range(2, 6) as $step) {
        $page->press('Next')->assertSee("Step {$step} of 6");
        expect($page->script($fits))->toBeTrue();
    }

    $page->wait(0.4)->screenshot(fullPage: true, filename: "welcome-phone-{$width}");
})->with([390, 320]);
