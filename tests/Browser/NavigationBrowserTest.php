<?php

declare(strict_types=1);

use App\Models\User;

it('keeps the main navigation in place and offers a new transfer button outside the new transfer page', function (): void {
    $this->actingAs(User::factory()->admin()->create(['name' => 'Filippo Fortino']));
    $navigationLeft = '() => document.querySelector(\'nav[aria-label="Main navigation"]\').getBoundingClientRect().left';

    $page = visit('/')->resize(1280, 940)
        ->assertSee('Drop files here')
        ->assertSeeIn('nav[aria-label="Main navigation"] a[aria-current="page"]', 'New transfer')
        ->assertMissing('header a[aria-label="New transfer"]');
    $page->script('() => document.fonts.ready');

    $left = $page->script($navigationLeft);

    $page->click('nav a:has-text("My transfers")')
        ->assertSee('No transfers yet')
        ->assertSeeIn('nav[aria-label="Main navigation"] a[aria-current="page"]', 'My transfers')
        ->assertVisible('header a[aria-label="New transfer"]');
    expect($page->script($navigationLeft))->toEqualWithDelta($left, 1);

    $page->click('nav a:has-text("Teams")')
        ->assertSee('Manage who can receive transfers')
        ->assertSeeIn('nav[aria-label="Main navigation"] a[aria-current="page"]', 'Teams')
        ->assertVisible('header a[aria-label="New transfer"]');
    expect($page->script($navigationLeft))->toEqualWithDelta($left, 1);

    $page->click('header a[aria-label="New transfer"]')
        ->assertSee('Drop files here')
        ->assertNoJavascriptErrors();
});

it('gives the mobile header controls space above them', function (): void {
    $this->actingAs(User::factory()->admin()->create());

    $page = visit('/transfers')->resize(390, 844)
        ->assertVisible('header a[aria-label="New transfer"]');

    $topOffsets = $page->script(<<<'JS'
        () => {
            const header = document.querySelector('header');
            return [...header.querySelectorAll(':scope > a, a[aria-label="New transfer"], button[aria-label$="account menu"]')]
                .map(element => element.getBoundingClientRect().top - header.getBoundingClientRect().top);
        }
        JS);

    expect($topOffsets)->toHaveCount(3);
    foreach ($topOffsets as $offset) {
        expect($offset)->toBeGreaterThanOrEqual(12);
    }

    foreach ([390, 320] as $width) {
        $page->resize($width, 844);
        expect($page->script(<<<'JS'
            () => {
                const nav = document.querySelector('nav[aria-label="Main navigation"]');
                const button = document.querySelector('header a[aria-label="New transfer"]');
                const links = [...nav.querySelectorAll('a')];
                return nav.getBoundingClientRect().top >= button.getBoundingClientRect().bottom
                    && links.every(link => link.getBoundingClientRect().top === nav.getBoundingClientRect().top)
                    && document.documentElement.scrollWidth <= innerWidth;
            }
            JS))->toBeTrue();
    }

    $page->resize(1280, 940);
    expect($page->script('() => document.querySelector("header").getBoundingClientRect().height'))->toEqual(64);
});
