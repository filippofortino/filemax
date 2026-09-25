<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

it('renders the public recipient at desktop and phone sizes without horizontal overflow', function (): void {
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $transfer = Transfer::factory()->for($sender, 'user')->create([
        'title' => 'Spot autunno — materiali finali',
        'message' => 'Ciao Marco, qui il master del 30" e il visual approvato. Il PDF è il brief aggiornato.',
        'expires_at' => '2026-09-25 12:00:00',
    ]);
    foreach (['Lenergy_Spot30s_v3.mp4' => 1200000000, 'Keyvisual_Autunno_2026.psd' => 480000000, 'Brief_Campagna_Q4.pdf' => 2100000] as $name => $size) {
        TransferFile::factory()->for($transfer)->create(['original_name' => $name, 'size' => $size]);
    }

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee('Spot autunno — materiali finali')
        ->assertSee('3 files · available until 25 Sept 2026')
        ->assertSee('Download all')
        ->assertNoJavascriptErrors();
    $page->script('document.fonts.ready');
    $page->screenshot(filename: 'filemax-recipient-desktop');
    $page->resize(390, 844)->assertSee('Brief_Campagna_Q4.pdf')->screenshot(filename: 'filemax-recipient-phone');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($transfer->refresh()->download_count)->toBe(0);
});

it('starts individual and all file downloads through the recipient controls', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $transfer = Transfer::factory()->create(['title' => 'Browser download']);
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => 'sample.txt']);
    Storage::disk('local')->put($file->path, 'test');

    visit(route('shared.show', $transfer->token))
        ->click('[aria-label="Download sample.txt"]')
        ->waitForEvent('networkidle')
        ->assertEnabled('[aria-label="Download sample.txt"]')
        ->press('Download all · 4 B')
        ->waitForEvent('networkidle')
        ->assertEnabled('Download all · 4 B')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->download_count)->toBe(2)
        ->and($transfer->archive_status)->toBe('ready')
        ->and($file->refresh()->download_count)->toBe(1);
});

it('shows denied and expired states without disclosing the file list', function (): void {
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $transfer = Transfer::factory()->for($sender, 'user')->create(['visibility' => 'teams']);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'private-file.pdf']);
    $user = User::factory()->create(['name' => 'Marta Bianchi', 'email' => 'marta@mediamaxcommunication.it']);
    $team = Team::factory()->create(['name' => 'Creative']);
    $user->teams()->attach($team);
    $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
    $this->actingAs($user);

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee("You don't have access to these files")
        ->assertSee('Creative')
        ->assertDontSee('private-file.pdf')
        ->assertAttributeContains('a:has-text("Ask Filippo for access")', 'href', 'mailto:'.$sender->email.'?')
        ->assertAttributeMissing('a:has-text("Ask Filippo for access")', 'role')
        ->assertAttribute('a:has-text("Go to Filemax")', 'href', '/')
        ->assertAttributeMissing('a:has-text("Go to Filemax")', 'role')
        ->assertScript(<<<'JS'
            () => {
                const link = [...document.querySelectorAll('a')].find((element) => element.textContent.trim() === 'Go to Filemax');
                const style = getComputedStyle(link);
                return style.borderTopWidth === '1px' && style.borderTopColor !== 'rgba(0, 0, 0, 0)';
            }
            JS)
        ->screenshot(filename: 'filemax-recipient-denied')
        ->resize(390, 844)
        ->screenshot(filename: 'filemax-recipient-denied-phone');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page
        ->click('Switch account')
        ->assertSee('Sign in')
        ->assertNoJavascriptErrors();

    $transfer->update(['expires_at' => now()->subDay()]);
    visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee('This link is no longer available')
        ->assertDontSee('private-file.pdf')
        ->assertDontSee('once downloaded')
        ->screenshot(filename: 'filemax-recipient-unavailable-desktop')
        ->resize(390, 844)
        ->screenshot(filename: 'filemax-recipient-unavailable-phone')
        ->assertNoJavascriptErrors();
});
