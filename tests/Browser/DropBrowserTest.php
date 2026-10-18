<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    Notification::fake();
    TransferFile::creating(static function (TransferFile $file): void {
        $file->part_size = 4;
    });
});

/** Drops files of the given names and byte sizes onto the page, as dragging them from the desktop would. */
function dropFilesOnPage(): string
{
    return <<<'JS'
() => {
    window.dropFiles = (files) => {
        const data = new DataTransfer();
        files.forEach(([name, size]) => data.items.add(new File([new Uint8Array(size)], name)));
        document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    };
}
JS;
}

it('creates a Drop and shows its link', function (): void {
    $this->actingAs(User::factory()->create());

    $page = visit('/drops/create')->resize(1280, 1100)
        ->assertSee('New Drop')
        ->click('button[type="submit"]')
        ->assertSee('Add a title so people know what to send.')
        ->fill('#drop-title', 'Foto e video — Fiera Rimini')
        ->fill('#drop-instructions', 'Foto in JPG alla massima risoluzione.')
        ->click('button[aria-controls="upload-restrictions"]')
        ->fill('#custom-types', 'indd, tar.gz')
        ->click('button[type="submit"]')
        ->assertSee('Use letters and numbers only for extensions, like .indd or .zip.')
        ->assertAttribute('#custom-types', 'aria-invalid', 'true')
        ->fill('#custom-types', '')
        ->click('button[type="submit"]')
        ->assertSee('Your Drop is ready')
        ->assertSee('Upload only')
        ->assertSee('Any file type')
        ->assertSee('No limit')
        ->assertSee('30 days after each upload')
        ->screenshot(filename: 'drop-ready-desktop');

    $drop = Drop::query()->sole();
    $page->assertValue('#share-link', route('shared.drops.show', $drop->token))
        ->assertNoJavascriptErrors();
    expect($drop->title)->toBe('Foto e video — Fiera Rimini')->and($drop->closes_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
});

it('lets someone without an account send files through a Drop, leaving out what doesn’t fit', function (): void {
    $owner = User::factory()->create(['name' => 'Filippo Fortino']);
    $drop = Drop::factory()->for($owner)->create(['title' => 'Foto e video — Fiera Rimini', 'file_types' => ['images'], 'max_file_size' => 10, 'max_submission_size' => 20]);

    $page = visit('/d/'.$drop->token)->resize(1280, 1480)
        ->assertSee('Filippo Fortino')
        ->assertSee('is asking for files')
        ->assertSee('Foto e video — Fiera Rimini')
        ->assertSee('Drop files here');
    $page->script(dropFilesOnPage());
    $page->script("() => window.dropFiles([['a.jpg', 8], ['big.jpg', 15], ['raw.zip', 4], ['b.jpg', 9], ['c.jpg', 6], ['d.jpg', 3]])");

    $page->assertSee('Over 10 B')
        ->assertSee('Not an image')
        ->assertSee('Over the 20 B total')
        ->assertSee('3 files won’t be sent because they don’t fit this Drop’s rules.')
        ->click('button[type="submit"]')
        ->assertSee('Enter your name so Filippo knows who sent the files.')
        ->screenshot(filename: 'drop-upload-rejected-desktop')
        ->click('button[aria-label="Remove big.jpg"]')
        ->assertDontSee('Over 10 B')
        ->fill('#uploader-name', 'Giulia Neri')
        ->fill('#uploader-message', 'Ecco la selezione.')
        ->click('button[type="submit"]')
        ->assertSee('Files sent')
        ->assertSee('Filippo Fortino can download them now.')
        ->assertSee('Send more files')
        ->screenshot(filename: 'drop-upload-sent-desktop');

    $transfer = Transfer::query()->sole();
    expect($transfer->status)->toBe('ready')
        ->and($transfer->sender_name)->toBe('Giulia Neri')
        ->and($transfer->files()->pluck('original_name')->all())->toBe(['a.jpg', 'b.jpg', 'd.jpg']);

    $page->click('Send more files')
        ->assertSee('Drop files here')
        ->assertValue('#uploader-name', 'Giulia Neri')
        ->assertValue('#uploader-message', '')
        ->assertNoJavascriptErrors();
});

it('fits the uploader on a phone', function (): void {
    $drop = Drop::factory()->create(['instructions' => 'Ciao Giulia, carica qui la selezione finale.', 'file_types' => ['images', 'videos']]);

    $page = visit('/d/'.$drop->token)->resize(390, 844)
        ->assertSee('Choose the files to send')
        ->assertSee('Choose files');
    $page->script(dropFilesOnPage());
    $page->script("() => window.dropFiles([['IMG_2041.HEIC', 3], ['IMG_2047.MOV', 5], ['Preventivo_definitivo_firmato.pdf', 4]])");
    $page->assertSee('Add more files')
        ->assertSee('Not an image or video')
        ->assertScript('() => document.querySelector(\'li:has(button[aria-label="Remove Preventivo_definitivo_firmato.pdf"]) [title="Preventivo_definitivo_firmato.pdf"]\').getBoundingClientRect().width > 100')
        ->screenshot(filename: 'drop-upload-phone');

    foreach ([390, 320] as $width) {
        $page->resize($width, 844);
        expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavascriptErrors();
});

it('leaves out the owner’s name and photo when they hide it', function (): void {
    $owner = User::factory()->create(['name' => 'Filippo Fortino']);
    $owner->settings()->create(['show_name_on_transfers' => false]);
    $drop = Drop::factory()->for($owner)->create(['title' => 'Foto e video — Fiera Rimini']);

    visit('/d/'.$drop->token)->resize(1280, 1100)
        ->assertSee('Foto e video — Fiera Rimini')
        ->assertDontSee('Filippo')
        ->assertDontSee('is asking for files')
        ->assertSee('So the recipient knows who sent the files.')
        ->assertSee('Only the recipient sees what you send.')
        ->screenshot(filename: 'drop-upload-hidden-owner')
        ->assertNoJavascriptErrors();

    $drop->update(['closed_at' => now()]);
    visit('/d/'.$drop->token)
        ->assertSee('This Drop is closed')
        ->assertSee('Ask whoever sent you this link for a new one')
        ->assertDontSee('Filippo')
        ->assertNoJavascriptErrors();
});

it('shows the closed page when the Drop closes while the form is open', function (): void {
    $drop = Drop::factory()->create();

    $page = visit('/d/'.$drop->token)->assertSee('Drop files here');
    $page->script(dropFilesOnPage());
    $page->script("() => window.dropFiles([['a.jpg', 4]])");

    $drop->update(['closed_at' => now()]);

    $page->fill('#uploader-name', 'Giulia Neri')
        ->click('button[type="submit"]')
        ->assertSee('This Drop is closed')
        ->assertNoJavascriptErrors();
    expect(Transfer::query()->count())->toBe(0);

    visit('/d/not-a-real-drop')->assertSee('This link isn’t working')->assertNoJavascriptErrors();
});

it('lists Drops and received files without overflowing a phone', function (): void {
    $owner = User::factory()->create();
    $drop = Drop::factory()->for($owner)->create(['title' => 'Loghi e linee guida — nuovo cliente con un titolo lungo']);
    Drop::factory()->for($owner)->closed()->create(['title' => 'Materiali stampa brochure']);
    $received = Transfer::factory()->for($owner)->received()->create(['drop_id' => $drop->id, 'sender_name' => 'Studio Vela', 'title' => $drop->title]);
    TransferFile::factory()->create(['transfer_id' => $received->id, 'original_name' => 'Logo_Studio_Vela_definitivo_versione_finale.svg', 'size' => 48_000_000]);
    $this->actingAs($owner);

    $page = visit('/drops')->resize(1280, 940)
        ->assertSee('2 Drops · 1 open')
        ->assertSee('1 upload')
        ->assertSee('Copy link')
        ->screenshot(filename: 'drops-list-desktop')
        ->click('Close')
        ->assertSee('Close this Drop?')
        ->click('Close Drop')
        ->assertSee('Drop closed')
        ->assertDontSee('Copy link');
    expect($drop->refresh()->closed_at)->not->toBeNull();

    $page->navigate('/transfers/received')
        ->assertSee('1 received · 1 new')
        ->assertSee('Studio Vela')
        ->screenshot(filename: 'received-list-desktop')
        ->click('Studio Vela')
        ->assertSee('The name they typed. Filemax doesn’t check it.')
        ->assertSee('Delete files')
        ->screenshot(filename: 'received-detail-desktop')
        ->navigate('/transfers/received')
        ->assertSee('1 received')
        ->assertDontSee('1 new');

    foreach (['/drops', '/transfers/received', '/transfers/received/'.$received->id] as $path) {
        $page->navigate($path)->resize(320, 740);
        expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavascriptErrors();
});
