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
    Transfer::factory()->count(17)->for($owner)->create();
    $this->actingAs($owner);

    visit('/transfers')
        ->assertSee('17 transfers · 17 active')
        ->assertSee('Showing 1–8 of 17')
        ->assertAttribute('nav[aria-label="Pagination"] a[aria-label="Page 1"]', 'aria-current', 'page')
        ->assertDisabled('nav[aria-label="Pagination"] button:has-text("Previous")')
        ->assertMissing('nav[aria-label="Pagination"] a:has-text("Previous")')
        ->assertAttributeContains('nav[aria-label="Pagination"] a:has-text("Next")', 'href', 'page=2')
        ->click('nav[aria-label="Pagination"] a:has-text("Next")')
        ->assertSee('Showing 9–16 of 17')
        ->click('nav[aria-label="Pagination"] a[aria-label="Page 3"]')
        ->assertSee('Showing 17–17 of 17')
        ->assertDisabled('nav[aria-label="Pagination"] button:has-text("Next")')
        ->assertMissing('nav[aria-label="Pagination"] a:has-text("Next")')
        ->click('nav[aria-label="Pagination"] a:has-text("Previous")')
        ->assertSee('Showing 9–16 of 17')
        ->click('nav[aria-label="Pagination"] a[aria-label="Page 1"]')
        ->assertSee('Showing 1–8 of 17')
        ->assertNoJavascriptErrors();
});

it('combines title search with audience and status counts across pages and clears every filter', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Creative']);
    $owner->teams()->attach($team);
    Transfer::factory()->count(9)->for($owner)->create(['title' => 'Campaign public version']);
    Transfer::factory()->for($owner)->create(['title' => 'Campaign public urgent', 'expires_at' => now()->addHours(6)]);
    Transfer::factory()->for($owner)->expired()->create(['title' => 'Campaign public expired']);
    Transfer::factory()->for($owner)->create(['title' => 'Campaign public deleted', 'revoked_at' => now()]);
    Transfer::factory()->for($owner)->create(['title' => 'Unrelated presentation']);
    $target = Transfer::factory()->for($owner)->create([
        'title' => 'Campaign team target',
        'visibility' => 'teams',
        'expires_at' => now()->addHours(12),
        'created_at' => now()->subDay(),
    ]);
    $target->teams()->attach($team);
    $this->actingAs($owner);

    $page = visit('/transfers')
        ->assertDontSee('Campaign team target')
        ->type('Search transfers', 'team target')
        ->assertSee('Campaign team target')
        ->assertSee('Showing 1–1 of 1')
        ->assertVisible('#transfer-search:focus')
        ->type('Search transfers', 'Campaign')
        ->keys('#transfer-search', 'Enter')
        ->assertSee('Showing 1–8 of 13')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("All")', '13')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Active")', '11')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Expiring soon")', '2')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Expired")', '2')
        ->click('[aria-label="Filter by status"] button:has-text("Active")')
        ->assertSee('Showing 1–8 of 11')
        ->click('nav[aria-label="Filter transfers"] a:has-text("Public")')
        ->assertSee('Showing 1–8 of 10')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("All")', '12')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Active")', '10')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Expiring soon")', '1')
        ->click('nav[aria-label="Pagination"] a:has-text("Next")')
        ->assertSee('Showing 9–10 of 10')
        ->assertQueryStringHas('search', 'Campaign')
        ->assertQueryStringHas('filter', 'public')
        ->assertQueryStringHas('status', 'active')
        ->refresh()
        ->assertValue('#transfer-search', 'Campaign')
        ->assertSee('Showing 9–10 of 10')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("Active")', 'aria-pressed', 'true')
        ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Public")', 'aria-current', 'page')
        ->click('[aria-label="Filter by status"] button:has-text("Expiring soon")')
        ->assertSee('Showing 1–1 of 1')
        ->assertSee('Campaign public urgent')
        ->assertQueryStringMissing('page')
        ->type('Search transfers', 'No matching title')
        ->assertSee('No matching transfers')
        ->assertMissing('nav[aria-label="Pagination"]')
        ->press('Clear filters')
        ->assertValue('#transfer-search', '')
        ->assertSee('Showing 1–8 of 14')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("All")', 'aria-pressed', 'true')
        ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Everyone")', 'aria-current', 'page')
        ->assertNoJavascriptErrors();

    $page->click('[aria-label="Filter by status"] button:has-text("Expired")')
        ->assertSee('Campaign public expired')
        ->assertSee('Campaign public deleted')
        ->assertSee('Deleted')
        ->assertDontSee('Campaign public urgent');
});

it('supports keyboard status selection and restores filters through browser history on a narrow screen', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create(['name' => 'Creative production and account management']);
    $owner->teams()->attach($team);
    Transfer::factory()->for($owner)->create(['title' => 'Campaign public active']);
    Transfer::factory()->for($owner)->expired()->create(['title' => 'Campaign public expired']);
    Transfer::factory()->for($owner)->create(['title' => 'Budget public urgent', 'expires_at' => now()->addHours(2)]);
    $target = Transfer::factory()->for($owner)->create([
        'title' => 'Campaign team urgent',
        'visibility' => 'teams',
        'expires_at' => now()->addHours(6),
    ]);
    $target->teams()->attach($team);
    $this->actingAs($owner);

    $page = visit('/transfers?search=Campaign&status=active')->resize(390, 844)
        ->assertValue('#transfer-search', 'Campaign')
        ->assertSee('Page 1 of 1')
        ->keys('[aria-label="Filter by status"] button:has-text("Active")', 'ArrowRight')
        ->assertVisible('[aria-label="Filter by status"] button:has-text("Expiring soon"):focus')
        ->keys(':focus', 'Space')
        ->assertSee('Page 1 of 1')
        ->assertSee('Campaign team urgent')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("Expiring soon")', 'aria-pressed', 'true')
        ->assertScript(<<<'JS'
() => {
    const selected = document.querySelector('[aria-label="Filter by status"] button[aria-pressed="true"]:focus-visible');
    if (!selected) return false;
    const style = getComputedStyle(selected);
    return style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2;
}
JS)
        ->keys('[aria-label="Filter by status"] button:has-text("Expiring soon")', 'Space')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("Expiring soon")', 'aria-pressed', 'true')
        ->click('nav[aria-label="Filter transfers"] a:has-text("Public")')
        ->assertSee('No matching transfers')
        ->type('Search transfers', 'Budget')
        ->keys('#transfer-search', 'Enter')
        ->assertSee('Budget public urgent')
        ->assertSee('Page 1 of 1')
        ->back()
        ->assertValue('#transfer-search', 'Campaign')
        ->assertSee('Campaign team urgent')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("Expiring soon")', 'aria-pressed', 'true')
        ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Everyone")', 'aria-current', 'page')
        ->forward()
        ->assertValue('#transfer-search', 'Budget')
        ->assertSee('Budget public urgent')
        ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Public")', 'aria-current', 'page')
        ->assertScript('() => document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();

    $page->screenshot(fullPage: false, filename: 'history-filtered-phone');
});

it('keeps mobile filters on one line and paginates with scrollable keyboard accessible audience chips', function (): void {
    $owner = User::factory()->create();
    $teams = Team::factory()->count(4)->sequence(
        ['name' => 'Brand strategy'],
        ['name' => 'Creative production'],
        ['name' => 'Mediamax'],
        ['name' => 'Studio Zeta'],
    )->create();
    Transfer::factory()->count(10)->for($owner)->has(TransferFile::factory(), 'files')->create([
        'title' => 'Campaign mobile delivery',
        'visibility' => 'teams',
        'expires_at' => now()->addHours(6),
        'download_count' => 3,
    ])->each(fn (Transfer $transfer) => $transfer->teams()->attach($teams->modelKeys()));
    $this->actingAs($owner);

    $page = visit('/transfers?search=Campaign&status=soon');

    foreach ([320, 390] as $width) {
        $page->resize($width, 844)
            ->assertSee('Page 1 of 2')
            ->assertDontSee('Showing 1–8 of 10')
            ->assertAttribute('[aria-label="Filter by status"] button[aria-pressed="true"]', 'aria-label', 'Expiring soon, 10')
            ->assertScript(<<<'JS'
() => {
    const statuses = [...document.querySelectorAll('[aria-label="Filter by status"] button')];
    const audience = document.querySelector('nav[aria-label="Filter transfers"]');
    const chips = [...audience.querySelectorAll('a')];
    return document.documentElement.scrollWidth <= window.innerWidth
        && statuses.map((button) => button.innerText.trim()).join('|') === 'All|Active|Soon|Expired'
        && statuses.every((button) => {
            const bounds = button.getBoundingClientRect();
            const content = document.createRange();
            content.selectNodeContents(button);
            return Math.abs(bounds.top - statuses[0].getBoundingClientRect().top) < 1
                && [...content.getClientRects()].filter((rect) => rect.width > 0).every((rect) =>
                    rect.left >= bounds.left && rect.right <= bounds.right);
        })
        && audience.scrollWidth > audience.clientWidth
        && chips.every((chip) => chip.getBoundingClientRect().height >= 44
            && Math.abs(chip.getBoundingClientRect().top - chips[0].getBoundingClientRect().top) < 1);
}
JS)
            ->screenshot(fullPage: true, filename: 'history-filters-'.$width);
    }

    $page->assertDisabled('nav[aria-label="Pagination"] button:has-text("Previous")')
        ->keys('nav[aria-label="Filter transfers"] a:has-text("Everyone")', 'Tab')
        ->keys(':focus', 'Tab')
        ->keys(':focus', 'Tab')
        ->keys(':focus', 'Tab')
        ->keys(':focus', 'Tab')
        ->assertVisible('nav[aria-label="Filter transfers"] a:has-text("Studio Zeta"):focus-visible')
        ->assertScript(<<<'JS'
() => {
    const audience = document.querySelector('nav[aria-label="Filter transfers"]');
    const chip = document.activeElement;
    const bounds = chip.getBoundingClientRect();
    const viewport = audience.getBoundingClientRect();
    const style = getComputedStyle(chip);
    return audience.scrollLeft > 0 && bounds.left >= viewport.left && bounds.right <= viewport.right
        && style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) >= 2
        && document.documentElement.scrollWidth <= window.innerWidth;
}
JS)
        ->keys(':focus', 'Enter')
        ->assertQueryStringHas('filter', $teams->last()->id)
        ->assertQueryStringHas('search', 'Campaign')
        ->assertQueryStringHas('status', 'soon')
        ->click('nav[aria-label="Pagination"] a:has-text("Next")')
        ->assertSee('Page 2 of 2')
        ->assertQueryStringHas('page', '2')
        ->assertQueryStringHas('filter', $teams->last()->id)
        ->assertQueryStringHas('search', 'Campaign')
        ->assertQueryStringHas('status', 'soon')
        ->assertDisabled('nav[aria-label="Pagination"] button:has-text("Next")')
        ->click('nav[aria-label="Filter transfers"] a:has-text("Everyone")')
        ->assertSee('Page 1 of 2')
        ->assertQueryStringMissing('page')
        ->type('Search transfers', 'Missing campaign')
        ->assertSee('No matching transfers')
        ->press('Clear filters')
        ->assertValue('#transfer-search', '')
        ->assertSee('Page 1 of 2')
        ->assertAttribute('[aria-label="Filter by status"] button:has-text("All")', 'aria-pressed', 'true')
        ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Everyone")', 'aria-current', 'page')
        ->screenshot(fullPage: true, filename: 'history-mobile-scroll-filters')
        ->resize(1280, 940)
        ->assertSee('Showing 1–8 of 10')
        ->assertDontSee('Page 1 of 2')
        ->assertSeeIn('[aria-label="Filter by status"] button:has-text("Expiring soon")', '10')
        ->assertVisible('nav[aria-label="Pagination"] a[aria-label="Page 2"]')
        ->assertScript('() => document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();
});

it('cancels an in-flight audience request immediately when a new search is typed', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $owner->teams()->attach($team);
    $target = Transfer::factory()->for($owner)->create(['title' => 'Needle team transfer', 'visibility' => 'teams']);
    $target->teams()->attach($team);
    Transfer::factory()->for($owner)->create(['title' => 'Unrelated public transfer']);
    $this->actingAs($owner);

    $page = visit('/transfers')->assertSee('Showing 1–2 of 2');
    $page->script(<<<'JS'
() => {
    const prototype = XMLHttpRequest.prototype;
    const original = { open: prototype.open, send: prototype.send, abort: prototype.abort };
    const urls = new WeakMap();
    const input = document.querySelector('#transfer-search');
    const probe = { held: null, handlingInput: false, cancelledDuringInput: false };
    const markInput = () => {
        probe.handlingInput = true;
    };
    const finishInput = () => { probe.handlingInput = false; };
    input.addEventListener('input', markInput, true);
    document.addEventListener('input', finishInput);
    prototype.open = function (method, url, ...args) {
        urls.set(this, new URL(url, window.location.href));
        return original.open.call(this, method, url, ...args);
    };
    prototype.send = function (...args) {
        const url = urls.get(this);
        if (!probe.held && url?.pathname === '/transfers' && url.searchParams.get('filter') === 'public') {
            probe.held = this;
            return;
        }
        return original.send.apply(this, args);
    };
    prototype.abort = function (...args) {
        const result = original.abort.apply(this, args);
        if (this === probe.held) {
            probe.cancelledDuringInput = probe.handlingInput;
            this.dispatchEvent(new Event('abort'));
        }
        return result;
    };
    probe.restore = () => {
        Object.assign(prototype, original);
        input.removeEventListener('input', markInput, true);
        document.removeEventListener('input', finishInput);
        delete window.transferRequestProbe;
    };
    window.transferRequestProbe = probe;
}
JS);

    try {
        $page->click('nav[aria-label="Filter transfers"] a:has-text("Public")')
            ->assertScript('() => window.transferRequestProbe.held !== null')
            ->type('Search transfers', 'Needle')
            ->assertScript('() => window.transferRequestProbe.cancelledDuringInput')
            ->assertQueryStringHas('search', 'Needle')
            ->assertSee('Showing 1–1 of 1')
            ->assertSee('Needle team transfer')
            ->assertDontSee('Unrelated public transfer')
            ->assertValue('#transfer-search', 'Needle')
            ->assertAttribute('nav[aria-label="Filter transfers"] a:has-text("Everyone")', 'aria-current', 'page')
            ->assertNoJavascriptErrors();
    } finally {
        $page->script('() => window.transferRequestProbe.restore()');
    }
});

it('honors returning to All while a status request is pending', function (): void {
    $owner = User::factory()->create();
    Transfer::factory()->for($owner)->create(['title' => 'Active probe']);
    Transfer::factory()->for($owner)->expired()->create(['title' => 'Expired probe']);
    $this->actingAs($owner);
    $page = visit('/transfers')->assertSee('Active probe')->assertSee('Expired probe');
    $page->script(<<<'JS'
() => {
    const prototype = XMLHttpRequest.prototype;
    const original = { open: prototype.open, send: prototype.send, abort: prototype.abort };
    const urls = new WeakMap();
    const probe = { held: null, args: null, cancelled: false, finished: false };
    prototype.open = function (method, url, ...args) {
        urls.set(this, new URL(url, location.href));
        return original.open.call(this, method, url, ...args);
    };
    prototype.send = function (...args) {
        if (!probe.held && urls.get(this)?.searchParams.get('status') === 'expired') {
            probe.held = this;
            probe.args = args;
            return;
        }
        return original.send.apply(this, args);
    };
    prototype.abort = function (...args) {
        const result = original.abort.apply(this, args);
        if (this === probe.held) {
            probe.cancelled = true;
            this.dispatchEvent(new Event('abort'));
        }
        return result;
    };
    const finish = (event) => {
        if (event.detail.visit.url.searchParams.get('status') === 'expired') probe.finished = true;
    };
    document.addEventListener('inertia:finish', finish);
    probe.release = () => { if (!probe.cancelled) original.send.apply(probe.held, probe.args); };
    probe.restore = () => {
        Object.assign(prototype, original);
        document.removeEventListener('inertia:finish', finish);
        delete window.statusRequestProbe;
    };
    window.statusRequestProbe = probe;
}
JS);
    try {
        $page->click('[aria-label="Filter by status"] button:has-text("Expired")')
            ->assertScript('() => window.statusRequestProbe.held !== null')
            ->click('[aria-label="Filter by status"] button:has-text("All")');
        $page->script('() => window.statusRequestProbe.release()');
        $page->assertScript('() => window.statusRequestProbe.finished')
            ->assertAttribute('div[aria-busy]', 'aria-busy', 'false')
            ->assertAttribute('[aria-label="Filter by status"] button:has-text("All")', 'aria-pressed', 'true')
            ->assertSee('Active probe')
            ->assertSee('Expired probe')
            ->assertNoJavascriptErrors();
    } finally {
        $page->script('() => window.statusRequestProbe.restore()');
    }
});

it('recovers a stale history page without clearing its filters', function (): void {
    $owner = User::factory()->create();
    Transfer::factory()->count(9)->for($owner)->create(['title' => 'Campaign active']);
    Transfer::factory()->count(8)->for($owner)->expired()->create(['title' => 'Campaign expired']);
    $this->actingAs($owner);

    visit('/transfers?filter=public&status=active&search=Campaign&page=3')
        ->assertQueryStringHas('page', '2')
        ->assertQueryStringHas('filter', 'public')
        ->assertQueryStringHas('status', 'active')
        ->assertQueryStringHas('search', 'Campaign')
        ->assertSee('Showing 9–9 of 9')
        ->assertDontSee('No matching transfers')
        ->click('nav[aria-label="Pagination"] a:has-text("Previous")')
        ->assertSee('Showing 1–8 of 9')
        ->assertNoJavascriptErrors();
});

it('shows the expiry date and time in the viewer timezone', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    Transfer::factory()->for($owner)->create(['title' => 'Spot autunno', 'expires_at' => '2026-09-25 12:00:00']);
    Transfer::factory()->for($owner)->create(['title' => 'Brandbook Mediamax', 'expires_at' => '2026-09-19 08:30:00']);
    Transfer::factory()->for($owner)->create(['title' => 'Consegna urgente', 'expires_at' => now()->addHours(6)]);
    Transfer::factory()->for($owner)->create(['title' => 'Ultima revisione', 'expires_at' => now()->addMinutes(30)]);
    $this->actingAs($owner);

    visit('/transfers')->withTimezone('Europe/Rome')
        ->assertSee('Expires 25 Sept 2026, 14:00')
        ->assertSee('Expired 19 Sept 2026, 10:30')
        ->assertSeeIn('a:has-text("Consegna urgente") span[title]:visible', 'Expires in 6 hours')
        ->assertSeeIn('a:has-text("Ultima revisione") span[title]:visible', 'Expires in 30 minutes')
        ->assertAttributeContains('a:has-text("Consegna urgente") span[title="Expires 20 Sept 2026, 20:00"]:visible', 'class', 'text-orange-700')
        ->assertVisible('a:has-text("Consegna urgente") span[title] svg[aria-hidden="true"]:visible')
        ->screenshot(fullPage: false, filename: 'history-expiring-soon')
        ->click('a:has-text("Spot autunno")')
        ->assertSee('Expires 25 Sept 2026, 14:00')
        ->assertNoJavascriptErrors();
});

it('extends an active transfer and disables extension while the request is processing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->create(['expires_at' => now()->addDays(2)]);
    $this->actingAs($owner);

    $page = visit(route('transfers.show', $transfer))->withTimezone('Europe/Rome')
        ->assertSee('Expires 22 Sept 2026, 14:00')
        ->assertEnabled('Extend by 7 days');
    $page->script(<<<'JS'
() => {
    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url, ...args) {
        this.isExtension = method.toUpperCase() === 'POST' && String(url).endsWith('/extend');
        return open.call(this, method, url, ...args);
    };
    XMLHttpRequest.prototype.send = function (body) {
        if (this.isExtension) {
            window.releaseExtension = () => send.call(this, body);
            return;
        }
        return send.call(this, body);
    };
}
JS);

    $page->press('Extend by 7 days')->assertDisabled('Extend by 7 days');
    $page->script('() => window.releaseExtension()');
    $page->assertSee('Expires 29 Sept 2026, 14:00')
        ->assertSeeIn('[data-slot="toast"]', 'Transfer extended')
        ->assertSeeIn('[data-slot="toast"]', 'Now expires 29 Sept 2026, 14:00.')
        ->assertMissing('button:has-text("Extend by 7 days")')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->expires_at->toDateTimeString())->toBe('2026-09-29 12:00:00');
});

it('restores an expired transfer on mobile and supports a second intentional extension', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->expired()->create();
    TransferFile::factory()->for($transfer)->create(['original_name' => 'extended.pdf']);
    $this->actingAs($owner);

    $page = visit(route('transfers.show', $transfer))->withTimezone('Europe/Rome')->resize(390, 844)
        ->assertSee('Expired 19 Sept 2026, 14:00')
        ->assertSee('This transfer has expired')
        ->assertSee('Your files are kept until 19 Oct 2026.')
        ->assertMissing('button:has-text("Download all")')
        ->assertMissing('[aria-label="Download extended.pdf"]')
        ->press('Reactivate for 7 days')
        ->assertSee('Expires 27 Sept 2026, 14:00')
        ->assertDontSee('Expired')
        ->assertSeeIn('[data-slot="toast"]', 'Transfer extended')
        ->assertSeeIn('[data-slot="toast"]', 'Now expires 27 Sept 2026, 14:00.')
        ->assertEnabled('Download all · 4 B')
        ->assertEnabled('[aria-label="Download extended.pdf"]')
        ->assertEnabled('Extend by 7 days');

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->screenshot(fullPage: true, filename: 'detail-extended-phone');
    $page->press('Extend by 7 days')
        ->assertSee('Expires 4 Oct 2026, 14:00')
        ->assertMissing('button:has-text("Extend by 7 days")')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->expires_at->toDateTimeString())->toBe('2026-10-04 12:00:00');
});

it('hides extension outside the extension window', function (string $expiresAt): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->create(['expires_at' => $expiresAt]);
    $this->actingAs($owner);

    visit(route('transfers.show', $transfer))
        ->assertSee($transfer->title)
        ->assertMissing('button:has-text("Extend by 7 days")')
        ->assertMissing('button:has-text("Reactivate for 7 days")')
        ->assertNoJavascriptErrors();
})->with([
    'more than seven days remaining' => '2026-09-28 12:00:00',
    'thirty days expired' => '2026-08-21 12:00:00',
]);

it('counts down the days left in the extension callout', function (int $days, string $expiry): void {
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->create(['expires_at' => now()->addDays($days)]);
    $this->actingAs($owner);

    visit(route('transfers.show', $transfer))->withTimezone('Europe/Rome')
        ->assertSee($expiry)
        ->assertEnabled('Extend by 7 days')
        ->assertNoJavascriptErrors();
})->with([
    'two days' => [2, 'Expires in 2 days'],
    'one day' => [1, 'Expires tomorrow'],
]);

it('keeps stale extension errors visible when the refreshed transfer is no longer eligible', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
    $owner = User::factory()->create();
    $transfer = Transfer::factory()->for($owner)->create(['expires_at' => now()->addDays(2)]);
    $this->actingAs($owner);

    $page = visit(route('transfers.show', $transfer))->withTimezone('Europe/Rome')
        ->assertEnabled('Extend by 7 days');
    $transfer->update(['expires_at' => now()->addDays(14)]);

    $page->press('Extend by 7 days')
        ->assertSeeIn('[role="alert"]', 'This transfer has changed. Refresh the page and try again.')
        ->assertSee('Expires 4 Oct 2026, 14:00')
        ->assertMissing('button:has-text("Extend by 7 days")')
        ->assertMissing('[data-slot="toast"]')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->expires_at->toDateTimeString())->toBe('2026-10-04 12:00:00');
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

    $page->click('nav[aria-label="Filter transfers"] a:has-text("Public")')->assertSee('Shooting Villa Borbone — selezione')->assertDontSee('Master spot + visual approvato');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("Mediamax")')->assertSee('Master spot + visual approvato')->assertDontSee('Shooting Villa Borbone — selezione');
    $page->click('nav[aria-label="Filter transfers"] a:has-text("Everyone")')->assertSee('Shooting Villa Borbone — selezione');

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
    $page->resize(390, 844)->press('button:has-text("Choose teams")')
        ->assertVisible('#team-search')
        ->assertScript(<<<'JS'
() => {
    const trigger = [...document.querySelectorAll('button')].find((button) => button.textContent.startsWith('Choose teams')).getBoundingClientRect();
    const popup = document.querySelector('[data-slot="popover-content"]').getBoundingClientRect();
    return popup.left >= 0 && popup.right <= window.innerWidth
        && Math.abs(popup.width - Math.max(256, trigger.width)) < 1;
}
JS)
        ->uncheck('[aria-label="Lenergy"]')
        ->keys(':focus', 'Escape')
        ->assertMissing('#team-search')
        ->assertVisible('button:has-text("Choose teams"):focus')
        ->assertSee('Save changes')
        ->press('Cancel')
        ->assertDontSee('Save changes')
        ->assertVisible('button:has-text("Change teams"):focus')
        ->resize(1280, 940);
    expect($transfer->teams()->pluck('teams.id')->all())->toEqualCanonicalizing([$mediamax->id, $lenergy->id]);

    $page->press('Change teams')
        ->wait(0.3)
        ->press('button:has-text("Choose teams")')
        ->assertChecked('[aria-label="Lenergy"]')
        ->uncheck('[aria-label="Lenergy"]')
        ->keys(':focus', 'Escape')
        ->assertMissing('#team-search')
        ->press('Save changes')
        ->assertDontSee('Save changes')
        ->assertSeeIn('[data-slot="toast"]', 'Sharing updated');
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

    $page->press('button:has-text("Delete transfer")')
        ->press('[role="dialog"] button:has-text("Delete transfer")')
        ->assertPathIs('/transfers')
        ->assertSeeIn('[data-slot="toast"]', 'Transfer deleted')
        ->assertSee('The link to “Shooting Villa Borbone — selezione” no longer works.')
        ->assertNoJavascriptErrors();
    expect($public->fresh()->revoked_at)->not->toBeNull();
});
