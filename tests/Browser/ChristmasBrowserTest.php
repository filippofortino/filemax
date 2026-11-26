<?php

declare(strict_types=1);

use App\Jobs\GenerateFilePreview;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Automated browsers never get the season on their own (it follows the browser's
 * clock), so these tests switch the decorations on with the ?christmas=on preview.
 */

it('wraps an upload in Christmas from the drop zone to the copied link', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());

    $page = visit('/?christmas=on')->resize(1280, 940)
        ->assertSee('Under the tree too.')
        ->assertPresent('a .xmas-hat')
        ->assertPresent('.xmas-snow')
        ->click('label:has-text("30 days")')
        ->assertSee('Outlasts the panettone.')
        ->assertNoJavascriptErrors();
    $page->hover('header a >> nth=0')->wait(0.6);
    // "Ho ho ho!" swaps in for the name, inside the logo, so it never covers what sits beside it.
    expect($page->script(<<<'JS'
() => {
    const logo = document.querySelector('header a');
    const ho = logo.querySelector('.xmas-ho').getBoundingClientRect();
    const name = logo.querySelector(':scope > span:not(.xmas-ho)');
    return ho.right <= logo.getBoundingClientRect().right + 1
        && ho.left >= name.getBoundingClientRect().left - 1
        && getComputedStyle(name).opacity < 0.5;
}
JS))->toBeTrue();
    expect($page->script('() => document.querySelector("link[rel=icon]").href'))->toEndWith('/favicon-christmas.svg');

    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'christmas-upload-empty');

    $page->script(<<<'JS'
() => {
    const send = XMLHttpRequest.prototype.send;
    const held = [];
    XMLHttpRequest.prototype.send = function (body) {
        if (!(body instanceof Blob)) return send.call(this, body);
        held.push(() => send.call(this, body));
        this.upload.dispatchEvent(new ProgressEvent('progress', { loaded: 2, total: 4, lengthComputable: true }));
    };
    window.releaseUploads = () => {
        XMLHttpRequest.prototype.send = send;
        held.forEach((release) => release());
    };
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: async () => {} } });
    const data = new DataTransfer();
    data.items.add(new File([new Uint8Array(4)], 'Keyvisual_Natale_2026.psd'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', { bubbles: true, dataTransfer: data }));
}
JS);
    $page->press('Create transfer')
        ->assertSee('Keep this tab open until the upload finishes. The elves are on it.')
        ->assertSee('50%')
        ->assertNotPresent('.xmas-snow');
    expect($page->script('() => getComputedStyle(document.querySelector("progress")).animationName'))->toBe('xmas-cane');
    expect($page->wait(0.1)->script('() => getComputedStyle(document.querySelector("progress")).getPropertyValue("--xmas-cane")'))->not->toBe('0px');

    $page->screenshot(fullPage: false, filename: 'christmas-upload-progress');

    $page->script('() => window.releaseUploads()');
    $page->assertVisible('h1:has-text("Wrapped and ready")')
        ->assertPresent('.xmas-puff')
        ->press('Copy link')
        ->assertSeeIn('[data-slot="toast"]', 'Checked it twice.')
        ->assertNoJavascriptErrors();
    $page->wait(2)->screenshot(fullPage: false, filename: 'christmas-upload-success');
});

it('hangs the lights on the download page and wishes happy holidays', function (): void {
    $transfer = Transfer::factory()->for(User::factory(), 'user')->create(['title' => 'Spot Natale — materiali finali']);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'Lenergy_Spot30s_v3.mp4']);

    $page = visit(route('shared.show', $transfer->token).'?christmas=on')->resize(390, 844)
        ->assertSee('Spot Natale — materiali finali')
        ->assertSee('Sent with Filemax. Happy holidays!')
        ->assertVisible('.xmas-bulb >> nth=0')
        ->assertMissing('.xmas-card-lights')
        ->assertPresent('a .xmas-hat')
        ->assertNoJavascriptErrors();
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->script('() => document.fonts.ready');
    $page->screenshot(filename: 'christmas-download-phone');
    $page->resize(1280, 940)
        ->assertVisible('section > .xmas-card-lights')
        ->assertMissing('header + div');
    expect($page->script('() => getComputedStyle(document.querySelector("section:has(> .xmas-card-lights)")).paddingTop'))->toBe('60px');
    $page->screenshot(fullPage: false, filename: 'christmas-download-desktop');
});

it('drapes five swags across the wider card of a photo grid', function (): void {
    Storage::fake('local');
    $transfer = Transfer::factory()->create(['title' => 'Foto evento di Natale']);
    $image = new Imagick;
    $image->newImage(1200, 800, '#9fb3bf');
    $image->setImageFormat('jpeg');
    foreach (range(0, 3) as $position) {
        $file = TransferFile::factory()->for($transfer)->create(['original_name' => "Stand_00{$position}.jpg", 'position' => $position, 'size' => mb_strlen($image->getImageBlob(), '8bit')]);
        Storage::disk('local')->put($file->path, $image->getImageBlob());
        dispatch_sync(new GenerateFilePreview($file->id));
    }

    $page = visit(route('shared.show', $transfer->token).'?christmas=on')->resize(1280, 940)
        ->assertVisible('[aria-label="Preview Stand_000.jpg"]')
        ->assertVisible('section > .xmas-card-lights')
        ->assertNoJavascriptErrors();
    expect($page->script('() => document.querySelectorAll(".xmas-card-lights circle").length'))->toBe(6);
    $page->script('() => document.fonts.ready');
    $page->screenshot(fullPage: false, filename: 'christmas-download-grid');
});

it('packs the decorations away in Grinch Mode and puts them back', function (): void {
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $page = visit('/account/settings?christmas=on')->resize(1280, 1200)
        ->assertSee('Grinch Mode')
        ->assertSee('Decorations are up')
        ->assertPresent('a .xmas-hat');
    $page->script('() => document.fonts.ready');
    $page->screenshot(filename: 'christmas-settings');

    $page->click('[aria-labelledby="grinch-label"]')
        ->assertSee('Decorations packed away. Bah, humbug.')
        ->assertPresent('.xmas-arm');
    $page->wait(0.6)->screenshot(fullPage: false, filename: 'christmas-grinch-stealing');
    $page->wait(1.2)
        ->assertNotPresent('.xmas-hat')
        ->assertNotPresent('.xmas-arm')
        ->assertVisible('img[src*="grinch"]')
        ->assertAttribute('[aria-labelledby="grinch-label"]', 'aria-checked', 'true');
    expect($page->script('() => document.querySelector("link[rel=icon]").href'))->toEndWith('/favicon.svg');
    $page->screenshot(filename: 'christmas-grinch');

    $page->navigate('/')
        ->assertSee('Anywhere on this page works.')
        ->assertDontSee('Under the tree too.')
        ->assertNotPresent('.xmas-hat')
        ->assertNotPresent('.xmas-snow');

    $page->navigate('/account/settings')
        ->click('[aria-labelledby="grinch-label"]')
        ->assertSee('Decorations are up')
        ->assertPresent('a .xmas-hat')
        ->assertNoJavascriptErrors();
    expect($page->script('() => document.querySelector("link[rel=icon]").href'))->toEndWith('/favicon-christmas.svg');
});

it('keeps automated browsers on the plain app unless they ask for the preview', function (): void {
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $page = visit('/account/settings')
        ->assertSee('Show my name and photo')
        ->assertDontSee('Grinch Mode')
        ->assertNotPresent('.xmas-hat')
        ->assertNoJavascriptErrors();
    expect($page->script('() => document.querySelector("link[rel=icon]").href'))->toEndWith('/favicon.svg');
});

it('keeps Grinch Mode on for the page when the browser refuses to store it', function (): void {
    $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $page = visit('/account/settings?christmas=on')->assertSee('Grinch Mode');
    $page->script('() => { Storage.prototype.setItem = () => { throw new DOMException("Quota exceeded", "QuotaExceededError"); }; }');
    $page->click('[aria-labelledby="grinch-label"]')
        ->wait(1.8)
        ->assertNotPresent('.xmas-hat')
        ->assertSee('Decorations packed away. Bah, humbug.')
        ->assertNoJavascriptErrors();
});
