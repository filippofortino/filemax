<?php

declare(strict_types=1);

use App\Jobs\GenerateFilePreview;
use App\Jobs\PrepareTransferArchive;
use App\Models\Organization;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00:00'));
});

it('renders missing shared links instead of a blank page', function (bool $authenticated): void {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }

    visit(route('shared.show', 'missing-transfer'))
        ->assertSee('This link is no longer available')
        ->assertSee('This transfer is unavailable or the link is incorrect.')
        ->assertNoJavascriptErrors();
})->with(['guest' => false, 'signed in' => true]);

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

    $page = visit(route('shared.show', $transfer->token))->withTimezone('Europe/Rome')->resize(1280, 940)
        ->assertSee('Spot autunno — materiali finali')
        ->assertSee('3 files · available until 25 Sept 2026, 14:00')
        ->assertSee('Download all')
        ->assertNoJavascriptErrors();
    $page->script('document.fonts.ready');
    $page->screenshot(filename: 'filemax-recipient-desktop');
    $page->resize(390, 844)->assertSee('Brief_Campagna_Q4.pdf')->screenshot(filename: 'filemax-recipient-phone');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($transfer->refresh()->download_count)->toBe(0);
});

it('downloads the original single file through both download controls', function (bool $owner): void {
    Storage::fake('local');
    Queue::fake([PrepareTransferArchive::class]);
    config(['filemax.disk' => 'local']);
    $transfer = Transfer::factory()->create(['title' => 'Browser download']);
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => 'sample.txt']);
    Storage::disk('local')->put($file->path, 'test');

    if ($owner) {
        $this->actingAs($transfer->user);
    }

    $page = visit($owner ? route('transfers.show', $transfer) : route('shared.show', $transfer->token))
        ->click('[aria-label="Download sample.txt"]')
        ->waitForEvent('networkidle')
        ->assertEnabled('[aria-label="Download sample.txt"]')
        ->press('Download · 4 B')
        ->waitForEvent('networkidle')
        ->assertEnabled('Download · 4 B')
        ->assertDontSee('Preparing your ZIP')
        ->assertNoJavascriptErrors();

    if ($owner) {
        $page->assertVisible('aside strong:has-text("2")');
    }

    $downloadPath = route('shared.files.download', [$transfer->token, $file], absolute: false);
    expect($page->script('() => performance.getEntriesByType("resource").filter(entry => entry.initiatorType === "fetch").map(entry => new URL(entry.name).pathname)'))
        ->toBe([$downloadPath, $downloadPath]);
    expect($transfer->refresh()->download_count)->toBe(2)
        ->and($transfer->archive_status)->toBeNull()
        ->and($transfer->archive_path)->toBeNull()
        ->and($transfer->archive_requested_at)->toBeNull()
        ->and($file->refresh()->download_count)->toBe(2);
    Queue::assertNotPushed(PrepareTransferArchive::class);
    expect(Storage::disk('local')->allFiles())->toBe([$file->path]);
})->with(['recipient' => false, 'owner' => true]);

it('downloads multiple files as a ZIP through the recipient control', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $transfer = Transfer::factory()->create();
    $files = TransferFile::factory()->for($transfer)->count(2)->sequence(
        ['original_name' => 'first.txt', 'position' => 0],
        ['original_name' => 'second.txt', 'position' => 1],
    )->create();
    foreach ($files as $file) {
        Storage::disk('local')->put($file->path, 'test');
    }

    visit(route('shared.show', $transfer->token))
        ->press('Download all · 8 B')
        ->waitForEvent('networkidle')
        ->assertEnabled('Download all · 8 B')
        ->assertNoJavascriptErrors();

    expect($transfer->refresh()->download_count)->toBe(1)
        ->and($transfer->archive_status)->toBe('ready')
        ->and($transfer->files()->sum('download_count'))->toBe(0);
    Storage::disk('local')->assertExists($transfer->archive_path);
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($transfer->archive_path)))->toBeTrue();
    expect($zip->numFiles)->toBe(2)
        ->and($zip->getFromName('first.txt'))->toBe('test')
        ->and($zip->getFromName('second.txt'))->toBe('test');
    $zip->close();
});

it('shows denied and expired states without disclosing the file list', function (): void {
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $transfer = Transfer::factory()->for($sender, 'user')->membersOnly()->create(['organization_id' => Organization::factory()->create(['name' => 'Mediamax Communication'])]);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'private-file.pdf']);
    $user = User::factory()->create(['name' => 'Marta Bianchi', 'email' => 'marta@studiobassi.it']);
    Organization::factory()->create(['name' => 'Creative'])->users()->attach($user);
    $this->actingAs($user);

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee("You don't have access to these files")
        ->assertSee('Filippo Fortino shared this transfer with members of Mediamax Communication only.')
        ->assertSee('marta@studiobassi.it, which isn’t a member.')
        ->assertDontSee('Creative')
        ->assertSee('Sent with Filemax.')
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

it('keeps a hidden sender off the denied page', function (): void {
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $sender->settings()->create(['show_name_on_transfers' => false]);
    $transfer = Transfer::factory()->for($sender, 'user')->membersOnly()->create();
    $this->actingAs(User::factory()->create(['name' => 'Marta Bianchi', 'email' => 'marta@mediamaxcommunication.it']));

    visit(route('shared.show', $transfer->token))
        ->assertSee('This transfer is shared with members of '.$transfer->organization->name.' only.')
        ->assertDontSee('Filippo')
        ->assertMissing('a[href^="mailto:"]')
        ->assertSee('Go to Filemax')
        ->assertNoJavascriptErrors();
});

it('shows the first four files until the recipient asks for all of them', function (): void {
    $transfer = Transfer::factory()->create();
    foreach (range(0, 5) as $position) {
        TransferFile::factory()->for($transfer)->create(['original_name' => 'file'.$position.'.pdf', 'position' => $position, 'preview_status' => 'none']);
    }

    visit(route('shared.show', $transfer->token))
        ->assertSee('file3.pdf')
        ->assertDontSee('file4.pdf')
        ->press('Show all 6 files')
        ->assertSee('file5.pdf')
        ->press('Show fewer files')
        ->assertDontSee('file5.pdf')
        ->assertNoJavascriptErrors();
});

it('shows photos as thumbnails and opens each one in the viewer, counting previews', function (): void {
    Storage::fake('local');
    $transfer = Transfer::factory()->create();
    $image = new Imagick;
    $image->newImage(1200, 800, '#9fb3bf');
    $image->setImageFormat('jpeg');

    $files = collect(range(0, 9))->map(function (int $position) use ($transfer, $image): TransferFile {
        $file = TransferFile::factory()->for($transfer)->create(['original_name' => 'Stand_00'.$position.'.jpg', 'position' => $position, 'size' => mb_strlen($image->getImageBlob(), '8bit'), 'mime_type' => 'application/octet-stream']);
        Storage::disk('local')->put($file->path, $image->getImageBlob());
        dispatch_sync(new GenerateFilePreview($file->id));

        return $file;
    });

    visit(route('shared.show', $transfer->token))
        ->assertSee('Stand_007.jpg')
        ->assertDontSee('Stand_008.jpg')
        ->assertSee('Show all 10 files')
        ->click('[aria-label="Preview Stand_000.jpg"]')
        ->assertSee('1 of 10')
        ->keys('[role="dialog"]', 'ArrowRight')
        ->assertSee('2 of 10')
        ->keys('[role="dialog"]', 'Escape')
        ->assertDontSee('2 of 10')
        ->click('[aria-label="List"]')
        ->assertPresent('[aria-label="Download Stand_000.jpg"]')
        ->assertNoJavascriptErrors();

    expect($files[0]->refresh()->preview_count)->toBe(1)
        ->and($files[1]->refresh()->preview_count)->toBe(1)
        ->and($transfer->refresh()->download_count)->toBe(0);
});

it('opens folders among the thumbnails, expands them in the list and zips one folder', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $transfer = Transfer::factory()->create(['title' => null]);
    $image = new Imagick;
    $image->newImage(1200, 800, '#9fb3bf');
    $image->setImageFormat('jpeg');

    $files = [['Fiera/Foto', 'Stand_000.jpg', $image->getImageBlob()], ['Fiera/Foto', 'Stand_001.jpg', $image->getImageBlob()], ['Fiera/Stampa', 'brief.txt', 'test'], [null, 'loose.txt', 'test']];
    foreach ($files as $position => [$folder, $name, $content]) {
        $file = TransferFile::factory()->for($transfer)->create(['folder' => $folder, 'original_name' => $name, 'position' => $position, 'size' => mb_strlen($content, '8bit'), 'mime_type' => 'application/octet-stream']);
        Storage::disk('local')->put($file->path, $content);
        dispatch_sync(new GenerateFilePreview($file->id));
    }

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 1060)
        ->assertSee('Fiera')
        ->assertSee('loose.txt')
        ->assertDontSee('Stand_000.jpg')
        ->click('[aria-label="Open folder Fiera, 3 files"]')
        ->assertSee('All files')
        ->assertVisible('[aria-label="Open folder Foto, 2 files"]')
        ->assertDontSee('loose.txt')
        ->click('[aria-label="Open folder Foto, 2 files"]')
        ->assertSee('Stand_001.jpg')
        ->click('[aria-label="List"]')
        ->assertSee('Stand_001.jpg')
        ->click('nav[aria-label="Folder"] button:has-text("Fiera")')
        ->assertDontSee('brief.txt')
        ->click('button:has-text("Stampa")')
        ->assertSee('brief.txt')
        ->click('[aria-label="Download Stampa as a zip"]')
        ->waitForEvent('networkidle')
        // While the zip prepares the button is relabelled; it comes back once the download has started.
        ->assertEnabled('[aria-label="Download Stampa as a zip"]')
        ->assertNoJavascriptErrors();

    $archive = $transfer->archives()->sole();
    expect($archive->folder)->toBe('Fiera/Stampa')
        ->and($archive->archive_status)->toBe('ready')
        ->and($archive->download_count)->toBe(1);
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($archive->archive_path)))->toBeTrue()
        ->and($zip->getFromName('Stampa/brief.txt'))->toBe('test');
    $zip->close();

    $page->click('nav[aria-label="Folder"] button:has-text("All files")')
        ->assertSee('loose.txt')
        ->assertNoJavascriptErrors();
});

it('shows a folder zip filling up on its button and keeps the button shown until it is ready', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $transfer = Transfer::factory()->create(['title' => null]);
    $image = new Imagick;
    $image->newImage(1200, 800, '#9fb3bf');
    $image->setImageFormat('jpeg');

    $file = TransferFile::factory()->for($transfer)->create(['folder' => 'Fiera', 'original_name' => 'Stand_000.jpg', 'size' => mb_strlen($image->getImageBlob(), '8bit'), 'mime_type' => 'application/octet-stream']);
    Storage::disk('local')->put($file->path, $image->getImageBlob());
    dispatch_sync(new GenerateFilePreview($file->id));
    TransferFile::factory()->for($transfer)->create(['original_name' => 'loose.txt', 'position' => 1]);

    $page = visit(route('shared.show', $transfer->token))->resize(1280, 940)->assertSee('loose.txt');
    // The archive stays at 40% so the in-between state can be checked.
    $page->script(<<<'JS'
() => {
    const fetch = window.fetch;
    window.fetch = (url, options) => new URL(url, location.href).pathname.endsWith('/archive')
        ? Promise.resolve(Response.json({status: 'processing', progress: 40, url: null}))
        : fetch(url, options);
}
JS);
    $page->hover('[aria-label="Open folder Fiera, 1 file"]')
        ->click('[aria-label="Download Fiera as a zip"]')
        ->assertVisible('[aria-label="Preparing Fiera as a zip, 40%"] svg')
        ->assertSeeIn('[aria-label="Preparing Fiera as a zip, 40%"]', '40')
        ->hover('[aria-label="List"]')
        ->wait(0.3)
        ->assertScript("getComputedStyle(document.querySelector('[aria-label=\"Preparing Fiera as a zip, 40%\"]').closest('.absolute')).opacity", '1')
        ->screenshot(filename: 'folder-zip-progress')
        ->assertNoJavascriptErrors();
});

it('shows a scannable QR code of the shared link at desktop and phone sizes', function (): void {
    $transfer = Transfer::factory()->create();
    TransferFile::factory()->for($transfer)->create();
    $url = route('shared.show', $transfer->token);
    $displayUrl = preg_replace('#^https?://#', '', $url);

    $page = visit($url)->resize(1280, 940)
        ->assertSee('Need it on your phone?')
        ->press('Show QR code')
        ->assertSee('Scan with a phone camera')
        ->assertVisible('[role="dialog"] svg[aria-label="QR code for '.$displayUrl.'"]')
        ->assertSeeIn('[role="dialog"]', $displayUrl)
        ->keys(':focus', 'Escape')
        ->assertDontSee('Scan with a phone camera')
        ->assertNoJavascriptErrors();

    $page->resize(390, 844)->assertSee('Need it on another phone?')->press('Show QR code')->assertSee('Scan with a phone camera');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
