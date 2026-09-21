<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;

it('shows an empty sender history and a working create action', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Filippo Fortino']));

    $page = visit('/transfers')->resize(1280, 940)
        ->assertSee('No transfers yet');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'history-empty');

    $page->click('[aria-label="Create a transfer"]')->assertSee('Transfer details')->assertNoJavascriptErrors();
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
    $page->resize(1280, 940);

    $page->click('nav[aria-label="Filter transfers"] a:has-text("Public links")')->assertSee('Shooting Villa Borbone — selezione')->assertDontSee('Master spot + visual approvato');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("Mediamax")')->assertSee('Master spot + visual approvato')->assertDontSee('Shooting Villa Borbone — selezione');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("All transfers")')->assertSee('Shooting Villa Borbone — selezione');

    $page->click('a:has-text("Master spot + visual approvato")')->assertSee('First opened')->assertSee('Change teams');
    $page->screenshot(fullPage: false, filename: 'detail-teams');

    $page->press('Change teams')->assertSee('Save changes');
    $page->screenshot(fullPage: false, filename: 'dialog-change-teams');
    $page->press('[aria-label="Remove Lenergy"]')->press('Cancel')->assertDontSee('Save changes');
    expect($transfer->teams()->pluck('teams.id')->all())->toEqualCanonicalizing([$mediamax->id, $lenergy->id]);

    $page->press('Change teams')->press('[aria-label="Remove Lenergy"]')->press('Save changes')->assertDontSee('Save changes');
    expect($transfer->teams()->pluck('teams.id')->all())->toBe([$mediamax->id]);

    $page->press('button:has-text("Delete transfer")')->assertSee('Delete this transfer?');
    expect($page->script('() => document.querySelector("[role=dialog]").contains(document.activeElement)'))->toBeTrue();
    $page->keys(':focus', 'Shift+Tab');
    expect($page->script('() => document.querySelector("[role=dialog]").contains(document.activeElement)'))->toBeTrue();
    $page->keys(':focus', 'Tab')->keys(':focus', 'Tab')->keys(':focus', 'Tab');
    expect($page->script('() => document.querySelector("[role=dialog]").contains(document.activeElement)'))->toBeTrue();
    $page->screenshot(fullPage: false, filename: 'dialog-delete-transfer');
    $page->keys(':focus', 'Escape')->assertDontSee('Delete this transfer?')->assertNoJavascriptErrors();
    expect($page->script('() => document.activeElement.textContent'))->toBe('Delete transfer');
    expect($transfer->fresh()->revoked_at)->toBeNull();

    $page->click('My transfers')->click('a:has-text("Shooting Villa Borbone — selezione")')->assertSee('Not opened yet');
    $page->screenshot(fullPage: false, filename: 'detail-unopened-public');
});
