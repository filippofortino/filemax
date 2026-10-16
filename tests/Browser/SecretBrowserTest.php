<?php

declare(strict_types=1);

use App\Models\Secret;
use App\Models\User;

it('encrypts a secret in the browser and opens it once from a link that carries the key', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Filippo Fortino']));
    $plaintext = 'Zk3p9QwL7xVb2NcTq8Rm';

    $page = visit('/secrets/create')->resize(1280, 940)
        ->assertSeeIn('nav[aria-label="Main navigation"] a[aria-current="page"]', 'Secrets')
        ->assertMissing('header a[aria-label="New transfer"]')
        ->assertMissing('header a[aria-label="New secret"]')
        ->assertAttribute('#secret-text', 'spellcheck', 'false')
        ->assertAttributeMissing('#secret-text', 'name')
        ->type('#secret-text', $plaintext)
        ->type('#secret-label', 'Token Google Ads (dev)');
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'secret-new');
    $page->press('Create secret link')
        ->assertSee('Your secret is ready')
        ->assertSee('Opens once · expires')
        ->assertNoJavascriptErrors()
        ->screenshot(fullPage: false, filename: 'secret-ready');

    $link = $page->value('#share-link');
    $secret = Secret::query()->sole();
    expect($link)->toMatch('/\/s\/'.$secret->token.'#[0-9A-HJKMNP-TV-Z]{20}$/')
        ->and($secret->label)->toBe('Token Google Ads (dev)')
        ->and($secret->payload)->not->toContain($plaintext)
        ->and(base64_decode((string) $secret->payload))->not->toContain($plaintext)
        // IV + one 256-byte padded block + GCM tag: the stored size doesn't reveal the 20-character length.
        ->and(mb_strlen(base64_decode((string) $secret->payload), '8bit'))->toBe(12 + 256 + 16);

    $recipient = visit($link)->resize(390, 844)
        ->assertSee('Filippo sent you a secret')
        ->assertSee('It opens once. After you view it, it’s deleted from Filemax.')
        ->assertDontSee($plaintext);
    expect($recipient->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $recipient->screenshot(fullPage: false, filename: 'secret-view-phone');

    // Two clicks before React re-renders: only one open request may go out, and the secret stays on screen.
    $recipient->script(<<<'JS'
        () => {
            const button = [...document.querySelectorAll('button')].find(button => button.textContent.includes('View secret'));
            button.click();
            button.click();
        }
        JS);
    $recipient->assertSee($plaintext)
        ->assertSee('This secret has been deleted from Filemax. Copy what you need before you leave this page.')
        ->assertSee('Copy secret')
        ->assertAttribute('#secret-body', 'translate', 'no')
        ->assertNoJavascriptErrors()
        ->screenshot(fullPage: false, filename: 'secret-open-phone');
    expect($recipient->script('() => location.hash'))->toBe('')
        ->and($recipient->text('#secret-body'))->toBe($plaintext)
        ->and($recipient->script('() => document.activeElement.textContent'))->toBe('Filippo sent you a secret')
        ->and($secret->refresh()->view_count)->toBe(1)
        ->and($secret->payload)->toBeNull();

    $recipient->refresh()->assertSee('This secret was already opened')->assertDontSee($plaintext)
        ->screenshot(fullPage: false, filename: 'secret-gone-phone');
});

it('unlocks a secret with a key sent separately and rejects a wrong key without using a view', function (): void {
    $this->actingAs(User::factory()->create(['name' => 'Filippo Fortino']));

    $page = visit('/secrets/create')
        ->type('#secret-text', 'wifi-ospiti-2026')
        ->click('Up to 5 times')
        ->assertSee('Deleted after 5 views, or at')
        ->press('Create secret link')
        ->assertSee('Opens up to 5 times · expires')
        ->click('[role="switch"]')
        ->assertSee('Copy key')
        ->screenshot(fullPage: false, filename: 'secret-ready-split');

    $link = $page->value('#share-link');
    $key = $page->value('#secret-key');
    expect($link)->not->toContain('#')->and($key)->toMatch('/^([0-9A-HJKMNP-TV-Z]{4}-){4}[0-9A-HJKMNP-TV-Z]{4}$/');

    $recipient = visit($link)
        ->assertSee('This secret needs its key')
        ->assertSee('Filippo Fortino sent the key separately. If you don’t have it, ask Filippo for it.')
        ->assertAttribute('#secret-key', 'spellcheck', 'false')
        ->assertAttributeMissing('#secret-key', 'name')
        ->type('#secret-key', 'AAAA-AAAA-AAAA-AAAA-AAAA')
        ->press('Unlock secret')
        ->assertSee('That key doesn’t open this secret. Check it and try again.')
        ->assertAttribute('#secret-key', 'aria-invalid', 'true')
        ->screenshot(fullPage: false, filename: 'secret-key-wrong');
    expect(Secret::query()->sole()->view_count)->toBe(0);

    $recipient->type('#secret-key', mb_strtolower(str_replace('-', ' ', $key)))
        ->press('Unlock secret')
        ->assertSee('wifi-ospiti-2026')
        ->assertSeeIn('#secret-body', 'wifi-ospiti-2026')
        ->assertSee('You can open this secret 4 more times')
        ->assertSee('1 of 5 views used')
        ->assertNoJavascriptErrors();
    expect($recipient->script('() => document.activeElement.tagName'))->toBe('H1');

    $secret = Secret::query()->sole();
    expect($secret->view_count)->toBe(1)->and($secret->wrong_key_count)->toBe(1)->and($secret->payload)->not->toBeNull();
});

it('uses a corrected link fragment without reloading the secret page', function (): void {
    $this->actingAs(User::factory()->create());

    $page = visit('/secrets/create')->type('#secret-text', 'fragment-change-secret')
        ->press('Create secret link')->assertSee('Your secret is ready');
    [$url, $key] = explode('#', $page->value('#share-link'));

    $recipient = visit($url)->assertSee('This secret needs its key');
    $recipient->script('() => { location.hash = "invalid"; }');
    $recipient->assertSee('The key in this link doesn’t open this secret.');
    $recipient->script('() => { location.hash = '.json_encode($key, JSON_THROW_ON_ERROR).'; }');
    $recipient->assertSee('View secret')->assertDontSee('The key in this link doesn’t open this secret.')
        ->press('View secret')->assertSee('fragment-change-secret')->assertNoJavascriptErrors();

    $recipient->script('() => { location.hash = "changed-after-opening"; }');
    $recipient->assertSee('fragment-change-secret')->assertDontSee('This secret needs its key');
    expect(Secret::query()->sole()->view_count)->toBe(1);
});

it('shows the opened state when someone else opens the secret first', function (): void {
    $secret = Secret::factory()->for(User::factory()->create(['name' => 'Filippo Fortino']))->create();

    $page = visit(route('shared.secrets.show', $secret->token).'#7KQMX4TA9RVB2HNEPW6C')->assertSee('View secret');
    $secret->update(['view_count' => 1, 'last_opened_at' => now(), 'payload' => null]);

    $page->press('View secret')
        ->assertSee('This secret was already opened')
        ->assertSee('If that wasn’t you, tell Filippo')
        ->assertNoJavascriptErrors();
});

it('lists secrets on their own page, apart from My transfers, and lets the owner delete one', function (): void {
    $owner = User::factory()->create();
    Secret::factory()->for($owner)->create(['label' => 'Accesso staging WordPress', 'max_views' => 5, 'view_count' => 2, 'last_opened_at' => now(), 'expires_at' => now()->addDays(3)]);
    $this->actingAs($owner);

    visit('/transfers')->assertSee('No transfers yet')->assertDontSee('Accesso staging WordPress');

    $page = visit('/secrets')->resize(1280, 940)
        ->assertSee('1 secret · 1 active')
        ->assertSee('Accesso staging WordPress')
        ->assertSee('2 of 5 views')
        ->assertVisible('header a[aria-label="New secret"]')
        ->screenshot(fullPage: false, filename: 'secret-list-desktop')
        ->resize(390, 844)
        ->screenshot(fullPage: false, filename: 'secret-list-phone');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('Accesso staging WordPress')
        ->assertSee('Filemax never had the key')
        ->assertSee('Opens up to 5 times')
        ->resize(1280, 940)
        ->screenshot(fullPage: false, filename: 'secret-detail-desktop')
        ->press('Delete secret')
        ->assertSee('Delete this secret?')
        ->click('[role="dialog"] button[type="submit"]')
        ->assertSee('Secret deleted')
        ->assertSee('Deleted')
        ->assertNoJavascriptErrors();

    expect(Secret::query()->sole()->revoked_at)->not->toBeNull();
});

it('keeps the views icon full size when its text wraps', function (): void {
    $owner = User::factory()->create();
    Secret::factory()->for($owner)->opened()->create(['label' => 'Secret stuff']);
    $this->actingAs($owner);

    visit('/secrets')->resize(800, 900)
        ->assertSee('Deleted after its view')
        ->assertScript(<<<'JS'
            () => {
                const cell = document.querySelector('[title="Times the secret was opened"]');
                return cell.getBoundingClientRect().height > 30 && cell.querySelector('svg').getBoundingClientRect().width === 16;
            }
            JS);
});
