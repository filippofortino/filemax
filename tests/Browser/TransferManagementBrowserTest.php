<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Carbon\CarbonImmutable;

it('shows an empty sender history and a working create action', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Filippo Fortino']));

    $page = visit('/transfers')->resize(1280, 940)
        ->assertSee('No transfers yet');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'history-empty');

    $page->click('[aria-label="Create a transfer"]')->assertSee('Transfer details')->assertNoJavascriptErrors();
});

it('uses links for available pages and disabled buttons at the pagination boundaries', function (): void {
    $owner = User::factory()->create();
    Transfer::factory()->count(21)->for($owner)->create();
    $this->actingAs($owner);

    visit('/transfers')
        ->assertSee('Page 1 of 2')
        ->assertDisabled('nav[aria-label="Pagination"] button:has-text("Previous")')
        ->assertMissing('nav[aria-label="Pagination"] a:has-text("Previous")')
        ->assertAttributeContains('nav[aria-label="Pagination"] a:has-text("Next")', 'href', 'page=2')
        ->click('nav[aria-label="Pagination"] a:has-text("Next")')
        ->assertSee('Page 2 of 2')
        ->assertDisabled('nav[aria-label="Pagination"] button:has-text("Next")')
        ->assertMissing('nav[aria-label="Pagination"] a:has-text("Next")')
        ->click('nav[aria-label="Pagination"] a:has-text("Previous")')
        ->assertSee('Page 1 of 2')
        ->assertNoJavascriptErrors();
});

it('shows the expiry date and time in the viewer timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    Transfer::factory()->for($owner)->create(['title' => 'Spot autunno', 'expires_at' => '2026-09-25 12:00:00']);
    Transfer::factory()->for($owner)->create(['title' => 'Brandbook Mediamax', 'expires_at' => '2026-09-19 08:30:00']);
    $this->actingAs($owner);

    visit('/transfers')->withTimezone('Europe/Rome')
        ->assertSee('Expires 25 Sept 2026, 14:00')
        ->assertSee('Expired 19 Sept 2026, 10:30')
        ->click('a:has-text("Spot autunno")')
        ->assertSee('Expires 25 Sept 2026, 14:00')
        ->assertNoJavascriptErrors();
});

it('saves team changes only on confirmation and keeps delete dialog keyboard focus inside', function (): void {
    $owner = User::factory()->create(['name' => 'Filippo Fortino']);
    $mediamax = Team::factory()->create(['name' => 'Mediamax']);
    $lenergy = Team::factory()->create(['name' => 'Lenergy']);
    $owner->teams()->attach([$mediamax->id, $lenergy->id]);
    $transfer = Transfer::factory()->for($owner)->create([
        'title' => 'Master spot + visual approvato',
        'visibility' => 'teams',
        'message' => 'Ultima versione prima della consegna — fatemi sapere entro venerdì.',
        'download_count' => 9,
        'first_opened_at' => now()->subHours(4),
        'last_downloaded_at' => now()->subHour(),
    ]);
    $transfer->teams()->attach([$mediamax->id, $lenergy->id]);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'Lenergy_Spot30s_v3.mp4', 'size' => 1200000000, 'download_count' => 6]);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'Keyvisual_Autunno_2026.psd', 'size' => 480000000, 'download_count' => 3, 'position' => 1]);
    $public = Transfer::factory()->for($owner)->create(['title' => 'Shooting Villa Borbone — selezione']);
    TransferFile::factory()->for($public)->create(['original_name' => 'VB_0012.jpg', 'size' => 24800000]);
    $expired = Transfer::factory()->for($owner)->expired()->create(['title' => 'Brandbook Mediamax']);
    TransferFile::factory()->for($expired)->create(['original_name' => 'Brandbook_Mediamax.pdf', 'size' => 32400000]);
    $this->actingAs($owner);

    $page = visit('/transfers')->resize(1280, 940)->assertSee('Master spot + visual approvato')->assertSee('Brandbook Mediamax');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'history-populated');

    $page->resize(900, 940);

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'history-tablet');
    $page->resize(390, 844)->assertSee('Master spot + visual approvato');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'history-phone');
    $page->resize(1280, 940);

    $page->click('nav[aria-label="Filter transfers"] a:has-text("Public links")')->assertSee('Shooting Villa Borbone — selezione')->assertDontSee('Master spot + visual approvato');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("Mediamax")')->assertSee('Master spot + visual approvato')->assertDontSee('Shooting Villa Borbone — selezione');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("All transfers")')->assertSee('Shooting Villa Borbone — selezione');

    $page->click('a:has-text("Master spot + visual approvato")')->assertSee('First opened')->assertSee('Change teams');
    $page->screenshot(fullPage: false, filename: 'detail-teams');
    $page->resize(768, 940)->assertSee('Download all');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'detail-tablet-narrow');
    $page->resize(900, 940)->assertSee('Download all');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'detail-tablet');
    $page->resize(390, 844)->assertSee('Lenergy_Spot30s_v3.mp4');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'detail-phone');
    $page->resize(1280, 940);

    $page->press('Change teams')->assertSee('Save changes');
    $page->screenshot(fullPage: false, filename: 'dialog-change-teams');
    $page->resize(390, 844)->press('[aria-label="Choose teams"]')
        ->assertVisible('#team-search')
        ->assertScript(<<<'JS'
() => {
    const trigger = document.querySelector('[aria-label="Choose teams"]').getBoundingClientRect();
    const popup = document.querySelector('[data-slot="popover-content"]').getBoundingClientRect();
    return popup.left >= 0 && popup.right <= window.innerWidth
        && Math.abs(popup.width - Math.max(256, trigger.width)) < 1;
}
JS)
        ->uncheck('[aria-label="Lenergy"]')
        ->keys(':focus', 'Escape')
        ->assertMissing('#team-search')
        ->assertVisible('[aria-label="Choose teams"]:focus')
        ->assertSee('Save changes')
        ->press('Cancel')
        ->assertDontSee('Save changes')
        ->assertVisible('button:has-text("Change teams"):focus')
        ->resize(1280, 940);
    expect($transfer->teams()->pluck('teams.id')->all())->toEqualCanonicalizing([$mediamax->id, $lenergy->id]);

    $page->press('Change teams')
        ->press('[aria-label="Choose teams"]')
        ->assertChecked('[aria-label="Lenergy"]')
        ->uncheck('[aria-label="Lenergy"]')
        ->keys(':focus', 'Escape')
        ->assertMissing('#team-search')
        ->press('Save changes')
        ->assertDontSee('Save changes');
    expect($transfer->teams()->pluck('teams.id')->all())->toBe([$mediamax->id]);

    $page->press('button:has-text("Delete transfer")')->assertSee('Delete this transfer?');
    $page->assertVisible('[role="dialog"]:has-text("Delete this transfer?"):focus-within');
    $page->keys(':focus', 'Shift+Tab');
    $page->assertVisible('[role="dialog"]:has-text("Delete this transfer?"):focus-within');
    $page->keys(':focus', 'Tab')->keys(':focus', 'Tab')->keys(':focus', 'Tab');
    $page->assertVisible('[role="dialog"]:has-text("Delete this transfer?"):focus-within');
    $page->screenshot(fullPage: false, filename: 'dialog-delete-transfer');
    $page->keys(':focus', 'Escape')->assertDontSee('Delete this transfer?')->assertNoJavascriptErrors();
    $page->assertVisible('button:has-text("Delete transfer"):focus');
    expect($transfer->fresh()->revoked_at)->toBeNull();

    $page->click('My transfers')->click('a:has-text("Shooting Villa Borbone — selezione")')->assertSee('Not opened yet');
    $page->screenshot(fullPage: false, filename: 'detail-unopened-public');
});
