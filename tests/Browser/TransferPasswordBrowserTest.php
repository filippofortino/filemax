<?php

declare(strict_types=1);

use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

it('unlocks a protected transfer without leaking files or a hidden sender', function (bool $showSender): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $sender->settings()->create(['show_name_on_transfers' => $showSender]);
    $transfer = Transfer::factory()->for($sender, 'user')->create([
        'title' => 'Confidential campaign',
        'message' => 'Private delivery notes',
        'password_hash' => Hash::make('delivery-secret'),
    ]);
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => 'confidential.txt']);
    Storage::disk('local')->put($file->path, 'test');

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee('This transfer needs a password')
        ->assertSourceMissing($transfer->title)
        ->assertSourceMissing($transfer->message)
        ->assertSourceMissing($file->original_name)
        ->assertMissing('[aria-label="Download confidential.txt"]')
        ->assertAttribute('#unlock-password', 'type', 'password');

    if ($showSender) {
        $page->assertSee('Filippo');
    } else {
        $page->assertSourceMissing($sender->name)
            ->assertSourceMissing($sender->email)
            ->assertMissing('a[href^="mailto:"]');
    }

    expect($transfer->refresh()->first_opened_at)->toBeNull();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(filename: 'password-recipient-'.($showSender ? 'visible' : 'hidden').'-desktop')
        ->resize(390, 844)
        ->assertSee('This transfer needs a password')
        ->screenshot(filename: 'password-recipient-'.($showSender ? 'visible' : 'hidden').'-phone');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->fill('#unlock-password', 'wrong-password')
        ->press('[aria-label="Show password"]')
        ->assertAttribute('#unlock-password', 'type', 'text')
        ->press('[aria-label="Hide password"]')
        ->assertAttribute('#unlock-password', 'type', 'password')
        ->press('Unlock files')
        ->assertVisible('#unlock-error')
        ->assertSee('This transfer needs a password')
        ->assertDontSee($file->original_name)
        ->fill('#unlock-password', 'delivery-secret')
        ->press('Unlock files')
        ->assertSee($transfer->title)
        ->assertSee($transfer->message)
        ->assertSee('Password protected')
        ->assertSee($file->original_name)
        ->click('[aria-label="Download confidential.txt"]')
        ->waitForEvent('networkidle')
        ->assertEnabled('[aria-label="Download confidential.txt"]')
        ->navigate(route('shared.show', $transfer->token))
        ->assertSee($transfer->title)
        ->assertDontSee('This transfer needs a password')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->first_opened_at)->not->toBeNull()
        ->and($transfer->download_count)->toBe(1);
})->with(['visible sender' => true, 'hidden sender' => false]);

it('shows password badges in history and detail and lets the owner open the shared page', function (): void {
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->create([
        'title' => 'Protected delivery',
        'password_hash' => Hash::make('delivery-secret'),
    ]);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'owner-file.pdf']);
    $this->actingAs($owner);

    $page = visit('/transfers')->resize(1280, 940)
        ->assertSeeIn('a:has-text("Protected delivery")', 'Password')
        ->resize(390, 844)
        ->assertSeeIn('a:has-text("Protected delivery")', 'Password');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->click('a:has-text("Protected delivery")')
        ->assertSee('Password protected')
        ->assertSee('owner-file.pdf')
        ->assertMissing('#transfer-password')
        ->navigate(route('shared.show', $transfer->token))
        ->assertSee('Password protected')
        ->assertSee('owner-file.pdf')
        ->assertDontSee('This transfer needs a password')
        ->assertNoJavascriptErrors();
});
