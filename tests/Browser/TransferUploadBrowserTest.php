<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

describe('concurrent upload chunks', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        config(['filemax.disk' => 'local']);
        $this->actingAs(User::factory()->create());
        TransferFile::creating(static function (TransferFile $file): void {
            $file->part_size = 4;
        });
    });

    it('shares four slots across files and completes out-of-order chunks once', function (array $sizes, array $firstParts, string $releasedPart, string $nextPart, int $overallProgress, int $fileProgress): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => window.dropUploadFiles('.json_encode($sizes, JSON_THROW_ON_ERROR).')');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4);
        expect($page->script('() => window.signedParts'))->toBe($firstParts);

        $page->script(<<<'JS'
() => {
    for (const upload of [...window.uploads].reverse()) upload.progress(1);
    window.uploads.find(upload => upload.key === 'file0.txt:1').progress(100);
}
JS);
        $page->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', $overallProgress)
            ->assertScript('document.querySelector(`progress[aria-label="Uploading file0.txt"]`).value', $fileProgress);
        expect($page->script('() => window.fileCompletions'))->toBe([]);
        $page->script('() => window.uploads.find(upload => upload.key === '.json_encode($releasedPart, JSON_THROW_ON_ERROR).').release()');
        $page->assertScript('window.uploads.length', 5);

        expect($page->script('() => window.signedParts.at(-1)'))->toBe($nextPart);
        $page->script('() => window.finishUploads()');
        $page->assertSee('Your link is ready')->assertNoJavascriptErrors();

        expect($page->script('() => window.maxActiveUploads'))->toBe(4)
            ->and($page->script('() => window.finalizations'))->toBe(1)
            ->and($page->script('() => window.fileCompletions.length'))->toBe(count($sizes))
            ->and($page->script('() => new Set(window.fileCompletions).size'))->toBe(count($sizes));
        $transfer = Transfer::query()->sole();
        expect($transfer->status)->toBe('ready');
        foreach ($transfer->files as $file) {
            expect(Storage::disk('local')->size($file->path))->toBe($file->size);
        }
    })->with([
        'one multipart file' => [[20], ['file0.txt:1', 'file0.txt:2', 'file0.txt:3', 'file0.txt:4'], 'file0.txt:3', 'file0.txt:5', 35, 7],
        'many small files' => [[1, 2, 3, 4, 2], ['file0.txt:1', 'file1.txt:1', 'file2.txt:1', 'file3.txt:1'], 'file2.txt:1', 'file4.txt:1', 33, 1],
        'mixed sizes including an empty file' => [[12, 0, 8], ['file0.txt:1', 'file1.txt:1', 'file2.txt:1', 'file0.txt:2'], 'file0.txt:2', 'file2.txt:2', 30, 5],
    ]);

    it('retains successful parts after a concurrent failure and retries only missing bytes', function (bool $signingFailure): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => window.dropUploadFiles([24, 4])');

        $initialUploads = $signingFailure ? 3 : 4;
        if ($signingFailure) {
            $page->script('() => { window.holdSigning = "file0.txt:2"; window.failSigning = "file0.txt:2"; }');
        }

        $page->press('Create transfer')->assertScript('window.uploads.length', $initialUploads);
        $page->script($signingFailure
            ? '() => window.releaseSigning()'
            : '() => window.uploads.find(upload => upload.key === "file0.txt:2").fail()');
        $page->assertSee($signingFailure ? 'Signing temporarily unavailable.' : 'Connection lost.');

        expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
        $page->script('() => window.finishUploads()');
        $page->assertSee('Some files could not be uploaded.')->assertSee('Done');
        expect($page->script('() => window.signedParts'))->toBe(['file0.txt:1', 'file1.txt:1', 'file0.txt:2', 'file0.txt:3'])
            ->and($page->script('() => window.fileCompletions'))->toBe(['file1.txt'])
            ->and($page->script('() => window.finalizations'))->toBe(0);

        $page->script('() => { window.autoReleaseUploads = false; window.holdSigning = "file0.txt:1"; window.releaseSigning = null; }');
        $page->press('Retry and create link')->assertScript('Boolean(window.releaseSigning)');
        $page->script('() => { const now = Date.now; Date.now = () => now() + 3000; window.releaseSigning(); }');
        $page->assertScript('window.uploads.length', $initialUploads + 4);
        $page->assertScript('document.querySelector(`progress[aria-label="Uploading file0.txt"]`).value', 8)->assertDontSee('About');
        expect($page->script('() => window.uploads.slice('.$initialUploads.').map(upload => upload.key).sort()'))->toBe(['file0.txt:2', 'file0.txt:4', 'file0.txt:5', 'file0.txt:6']);
        $page->script('() => window.uploads.find(upload => upload.key === "file0.txt:6").release()');
        $page->assertScript('document.querySelector(`progress[aria-label="Uploading file0.txt"]`).value', 12);

        expect($page->script('() => window.fileCompletions'))->toBe(['file1.txt']);
        $page->script('() => window.finishUploads()');
        $page->assertSee('Your link is ready')->assertNoJavascriptErrors();
        expect($page->script('() => window.uploads.length'))->toBe($initialUploads + 4)
            ->and($page->script('() => window.fileCompletions'))->toBe(['file1.txt', 'file0.txt'])
            ->and($page->script('() => window.finalizations'))->toBe(1);
    })->with(['upload failure' => false, 'signing failure' => true]);

    it('retries a failed file completion without uploading its bytes again', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.failCompletion = "file0.txt"; window.dropUploadFiles([12, 4]); window.autoReleaseUploads = true; }');
        $page->press('Create transfer')->assertSee('Some files could not be uploaded.');
        expect($page->script('() => window.uploads.length'))->toBe(4);
        $page->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
        expect($page->script('() => window.uploads.length'))->toBe(4)
            ->and($page->script('() => window.fileCompletions.filter(name => name === "file0.txt").length'))->toBe(2)
            ->and($page->script('() => window.fileCompletions.filter(name => name === "file1.txt").length'))->toBe(1)
            ->and($page->script('() => window.finalizations'))->toBe(1);
    });

    it('waits for four aborted uploads and draft revocation before allowing another upload', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.holdDelete = true; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4)->press('Cancel upload');
        $page->assertScript('window.abortedUploads', 4)->assertScript('Boolean(window.releaseDelete)');
        expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
        $page->script('() => window.uploads.forEach(upload => upload.progress(100))');
        $page->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 0);
        expect($page->script('() => window.signedParts.length'))->toBe(4)
            ->and($page->script('() => window.fileCompletions'))->toBe([])
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $page->script('() => window.releaseDelete()');
        $page->assertSee('Drop files here');

        expect(Transfer::query()->sole()->revoked_at)->not->toBeNull();
        $page->click('My transfers')->assertSee('No transfers yet')->click('nav a:has-text("New transfer")')->assertSee('Drop files here');
        expect($page->script('() => window.leavePrompts'))->toBe(0);

        $page->script('() => window.dropUploadFiles([4], "new")');
        $page->press('Create transfer')->assertScript('window.uploads.length', 5);
        $page->script(<<<'JS'
() => {
    for (const upload of window.uploads.slice(0, 4)) {
        upload.progress(100);
        upload.xhr.dispatchEvent(new ProgressEvent('load'));
    }
}
JS);
        $page->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 0);
        expect($page->script('() => window.uploads.length'))->toBe(5)
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $page->script('() => window.uploads.find(upload => upload.key === "new0.txt:1").release()');
        $page->assertSee('Your link is ready')->assertNoJavascriptErrors();
        expect($page->script('() => window.fileCompletions'))->toBe(['new0.txt'])
            ->and($page->script('() => window.finalizations'))->toBe(1);
    });
    it('preserves the draft when cancellation fails and allows revocation to be retried', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.failDelete = true; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4)->press('Cancel upload');
        $page->assertSee('Revocation temporarily unavailable.')->assertSee('file0.txt')->assertSee('file1.txt');
        expect(Transfer::query()->sole()->revoked_at)->toBeNull()
            ->and($page->script('() => window.abortedUploads'))->toBe(4)
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $page->press('Cancel upload')->assertSee('Drop files here')->assertNoJavascriptErrors();
        expect(Transfer::query()->sole()->revoked_at)->not->toBeNull();
    });

    it('waits for an aborted signing response without starting its upload', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.holdSigning = "file0.txt:2"; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 3)->assertScript('Boolean(window.releaseSigning)')->press('Cancel upload');
        $page->assertScript('window.abortedUploads', 3)->assertScript('window.revocations', 0);
        expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
        $page->script('() => window.releaseSigning()');
        $page->assertSee('Drop files here')->assertNoJavascriptErrors();
        expect($page->script('() => window.uploads.length'))->toBe(3)
            ->and($page->script('() => window.signedParts.length'))->toBe(4)
            ->and($page->script('() => window.fileCompletions'))->toBe([])
            ->and($page->script('() => window.finalizations'))->toBe(0)
            ->and(Transfer::query()->sole()->revoked_at)->not->toBeNull();
    });
});

it('uses the current XSRF cookie only for same-origin upload requests', function (bool $external): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script('window.externalUpload = '.($external ? 'true' : 'false'));
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'csrf.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const fetch = window.fetch;
    window.jsonCsrfChecks = [];
    window.uploadHeaders = {};
    window.fetch = async (url, options) => {
        const cookie = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)?.[1];
        window.jsonCsrfChecks.push(Boolean(cookie) && options.headers['X-XSRF-TOKEN'] === decodeURIComponent(cookie) && !options.headers['X-CSRF-TOKEN']);
        const response = await fetch(url, options);
        const body = await response.clone().json();
        if ('completed' in body) {
            document.cookie = 'XSRF-TOKEN=rotated%2Btoken; path=/';
            if (window.externalUpload) return Response.json({...body, url: 'https://uploads.example.test/object'});
        }
        return response;
    };
    const setHeader = XMLHttpRequest.prototype.setRequestHeader;
    XMLHttpRequest.prototype.setRequestHeader = function(name, value) {
        window.uploadHeaders[name] = value;
        return setHeader.call(this, name, value);
    };
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) { this.dispatchEvent(new ProgressEvent('error')); return; }
        return send.call(this, body);
    };
}
JS);
    $page->press('Create transfer')->assertSee('Some files could not be uploaded.')->assertNoJavascriptErrors();

    expect($page->script('() => window.jsonCsrfChecks.length > 0 && window.jsonCsrfChecks.every(Boolean)'))->toBeTrue()
        ->and($page->script("() => window.uploadHeaders['X-CSRF-TOKEN'] ?? null"))->toBeNull()
        ->and($page->script("() => window.uploadHeaders['X-XSRF-TOKEN'] ?? null"))->toBe($external ? null : 'rotated+token');
})->with(['local upload' => false, 'object storage upload' => true]);

it('recovers a failed multi-file upload while preserving finished files and sharing', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $team = Team::factory()->create(['name' => 'Mediamax']);
    $user->teams()->attach($team);
    $this->actingAs($user);
    $page = visit('/')->withTimezone('Europe/Rome')->resize(1280, 940)->assertSee('Drop files here');
    expect($page->script('() => document.body.innerText'))->toMatch('/Available until \d{1,2} \w+ \d{4}, \d{2}:\d{2}\./');
    $page->script('() => document.fonts.ready');
    $page->screenshot(filename: 'upload-empty');
    $page->fill('#transfer-title', 'Spot autunno — materiali finali')->fill('#transfer-message', 'Ciao Marco, qui i materiali approvati.');
    $page->click('Specific teams')->click('[aria-label="Choose teams"]')->check('[aria-label="Mediamax"]')->keys('#team-search', 'Escape');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer();
    data.items.add(new File(['hello'], 'Brief_Campagna_Q4.pdf'));
    data.items.add(new File([new Uint8Array(1024)], 'Lenergy_Spot30s_v3.mp4'));
    data.items.add(new File(['visual'], 'Keyvisual_Autunno_2026.psd'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const original = XMLHttpRequest.prototype.send;
    let failed = false;
    window.uploadPutCount = 0;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) {
            window.uploadPutCount++;
            if (body.size === 1024 && !failed) { failed = true; window.failUpload = () => this.dispatchEvent(new ProgressEvent('error')); return; }
        }
        return original.call(this, body);
    };
}
JS);
    $page->assertSee('Lenergy_Spot30s_v3.mp4')->screenshot(filename: 'upload-teams-selected');

    foreach ([390, 768] as $width) {
        $page->resize($width, 940);
        expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->screenshot(filename: 'upload-'.$width);
    }

    $page->resize(1280, 940)->press('Create transfer')->assertSee('Uploading…')
        ->assertVisible('progress[aria-label="Overall upload progress"]')
        ->assertVisible('progress[aria-label="Uploading Lenergy_Spot30s_v3.mp4"]');
    $page->screenshot(filename: 'upload-progress');
    $page->script('() => new Promise(resolve => { const timer = setInterval(() => { if (window.failUpload) { clearInterval(timer); window.failUpload(); resolve(true); } }, 20); })');
    $page->assertSee('A little interruption')->assertSee('Some files could not be uploaded.');
    $page->screenshot(filename: 'upload-failed');
    expect(Transfer::query()->sole()->status)->toBe('uploading');
    $page->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
    expect($page->script('() => document.body.innerText'))->toMatch('/expires \d{1,2} \w+ \d{4}, \d{2}:\d{2}/');
    $page->screenshot(filename: 'upload-ready');
    expect($page->script('() => window.uploadPutCount'))->toBe(4);
    $transfer = Transfer::query()->sole();
    expect($transfer->status)->toBe('ready')->and($transfer->teams()->sole()->id)->toBe($team->id);
    expect($transfer->files()->count())->toBe(3);
    expect(Storage::disk('local')->size($transfer->files()->where('position', 1)->sole()->path))->toBe(1024);
    $page->click('View transfer')->assertSee('Downloads')->assertSee('Not opened yet')->assertSee('No download clicks yet.');
    $page->screenshot(filename: 'transfer-detail-unopened');
    $page->click('My transfers')->assertSee('1 transfers')->assertSee('Spot autunno');
    $page->screenshot(filename: 'transfer-history');
});

it('asks before leaving an unfinished upload and respects the choice', function (bool $leave): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer();
    data.items.add(new File(['finished'], 'finished.txt'));
    data.items.add(new File(['pending'], 'pending.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    const abort = XMLHttpRequest.prototype.abort;
    XMLHttpRequest.prototype.abort = function() {
        if (this.heldUpload) { this.heldUpload = false; this.dispatchEvent(new ProgressEvent('abort')); this.dispatchEvent(new ProgressEvent('loadend')); return; }
        return abort.call(this);
    };
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob && body.size === 7) {
            this.heldUpload = true;
            window.resumeUpload = () => { this.heldUpload = false; send.call(this, body); };
            return;
        }
        return send.call(this, body);
    };
    window.leavePrompts = 0;
    window.allowNavigation = false;
    window.confirm = () => { window.leavePrompts++; return window.allowNavigation; };
}
JS);
    $page->press('Create transfer')->assertSee('Done')->assertSee('Cancel upload');
    expect(Transfer::query()->sole()->files()->where('status', 'ready')->count())->toBe(1);

    if ($leave) {
        $page->script('() => { window.allowNavigation = true; }');
    }

    $page->click('My transfers');
    expect($page->script('() => window.leavePrompts'))->toBe(1);

    if ($leave) {
        $page->assertSee('No transfers yet')->click('nav a:has-text("New transfer")')->assertSee('Drop files here')->assertDontSee('finished.txt');
        expect(Transfer::query()->sole()->status)->toBe('uploading');
    } else {
        $page->assertSee('Cancel upload')->assertSee('finished.txt')->assertSee('pending.txt');
        $page->script('() => window.resumeUpload()');
        $page->assertSee('Your link is ready')->click('View transfer')->assertSee('Downloads');
        expect(Transfer::query()->sole()->status)->toBe('ready');
    }

    expect($page->script('() => window.leavePrompts'))->toBe(1);
    $page->assertNoJavascriptErrors();
})->with(['stay' => false, 'leave' => true]);

it('guards navigation while the upload draft is being created', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'draft.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const fetch = window.fetch;
    window.fetch = async (url, options) => {
        if (options?.method === 'POST' && new URL(url, location.href).pathname === '/transfers') {
            await new Promise(resolve => { window.resumeDraft = resolve; });
        }
        return fetch(url, options);
    };
    window.leavePrompts = 0;
    window.confirm = () => { window.leavePrompts++; return false; };
}
JS);
    $page->press('Create transfer')->assertSee('Uploading…')->click('My transfers')->assertSee('Uploading…')->assertSee('draft.txt');
    expect($page->script('() => window.leavePrompts'))->toBe(1);
    expect(Transfer::query()->count())->toBe(0);

    $page->script('() => window.resumeDraft()');
    $page->assertSee('Your link is ready')->click('Send another')->assertSee('Drop files here')->assertNoJavascriptErrors();
    expect($page->script('() => window.leavePrompts'))->toBe(1);
    expect(Transfer::query()->sole()->status)->toBe('ready');
});

it('can repair sharing after losing membership during an upload', function (): void {
    Storage::fake('local');
    $user = User::factory()->create();
    $oldTeam = Team::factory()->create(['name' => 'Old team']);
    $newTeam = Team::factory()->create(['name' => 'New team']);
    $user->teams()->attach([$oldTeam->id, $newTeam->id]);
    $this->actingAs($user);
    $page = visit('/')->click('Specific teams')->click('[aria-label="Choose teams"]')->check('[aria-label="Old team"]')->keys('#team-search', 'Escape');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'repair.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    window.uploadPutCount = 0;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) { window.uploadPutCount++; window.resumeUpload = () => send.call(this, body); return; }
        return send.call(this, body);
    };
    window.leavePrompts = 0;
    window.confirm = () => { window.leavePrompts++; return false; };
}
JS);
    $page->press('Create transfer')->assertSee('Uploading…');
    $user->teams()->detach($oldTeam);
    $page->script('() => new Promise(resolve => { const timer = setInterval(() => { if (window.resumeUpload) { clearInterval(timer); window.resumeUpload(); resolve(true); } }, 20); })');
    $page->assertSee('Ready to finish')->assertSee('Select at least one');
    $page->click('[aria-label="Choose teams"]')->check('[aria-label="New team"]')->keys('#team-search', 'Escape')->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->teams()->sole()->id)->toBe($newTeam->id);
    expect($page->script('() => window.uploadPutCount'))->toBe(1);
    expect($page->script('() => window.leavePrompts'))->toBe(0);
});

it('shows the first four ready files until all of them are requested', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer();
    for (let index = 0; index < 5; index++) data.items.add(new File(['hello'], `file${index}.txt`));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
}
JS);
    $page->press('Create transfer')->assertSee('Your link is ready')
        ->assertSee('file3.txt')
        ->assertDontSee('file4.txt')
        ->press('Show all 5 files')
        ->assertSee('file4.txt')
        ->press('Show fewer files')
        ->assertDontSee('file4.txt')
        ->assertNoJavascriptErrors();
});

function controlledTransferUploads(): string
{
    return <<<'JS'
() => {
    window.uploads = [];
    window.signedParts = [];
    window.fileCompletions = [];
    window.finalizations = 0;
    window.revocations = 0;
    window.abortedUploads = 0;
    window.activeUploads = 0;
    window.maxActiveUploads = 0;
    window.autoReleaseUploads = false;
    window.leavePrompts = 0;
    window.confirm = () => { window.leavePrompts++; return false; };
    const names = {};
    window.dropUploadFiles = (sizes, prefix = 'file') => {
        const data = new DataTransfer();
        sizes.forEach((size, index) => data.items.add(new File([new Uint8Array(size)], `${prefix}${index}.txt`)));
        document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    };
    const fetch = window.fetch;
    window.fetch = async (url, options) => {
        const path = new URL(url, location.href).pathname;
        const part = path.match(/\/files\/([^/]+)\/parts\/(\d+)$/);
        const completion = path.match(/\/files\/([^/]+)\/upload$/);
        if (part) window.signedParts.push(`${names[part[1]]}:${part[2]}`);
        if (completion && options?.method === 'POST') {
            const name = names[completion[1]];
            window.fileCompletions.push(name);
            if (window.failCompletion === name) {
                window.failCompletion = null;
                return Response.json({message: 'File completion temporarily unavailable.'}, {status: 503});
            }
        }
        if (/^\/transfers\/[^/]+\/upload$/.test(path)) window.finalizations++;
        if (options?.method === 'DELETE') window.revocations++;
        if (options?.method === 'DELETE' && window.holdDelete) await new Promise(resolve => { window.releaseDelete = resolve; });
        if (options?.method === 'DELETE' && window.failDelete) {
            window.failDelete = false;
            return Response.json({message: 'Revocation temporarily unavailable.'}, {status: 503});
        }
        const response = await fetch(url, options);
        if (part && window.holdSigning === `${names[part[1]]}:${part[2]}`) {
            await new Promise(resolve => { window.releaseSigning = resolve; });
            window.holdSigning = null;
        }
        if (part && window.failSigning === `${names[part[1]]}:${part[2]}`) {
            window.failSigning = null;
            return Response.json({message: 'Signing temporarily unavailable.'}, {status: 503});
        }
        if (path === '/transfers' && options?.method === 'POST') {
            const {transfer} = await response.clone().json();
            transfer.files.forEach(file => { names[file.id] = file.original_name; });
        }
        return response;
    };
    const open = XMLHttpRequest.prototype.open;
    const send = XMLHttpRequest.prototype.send;
    const abort = XMLHttpRequest.prototype.abort;
    XMLHttpRequest.prototype.open = function(method, url, ...rest) {
        this.uploadUrl = String(url);
        return open.call(this, method, url, ...rest);
    };
    XMLHttpRequest.prototype.send = function(body) {
        if (!(body instanceof Blob)) return send.call(this, body);
        const match = new URL(this.uploadUrl, location.href).pathname.match(/\/files\/([^/]+)\/parts\/(\d+)$/);
        const xhr = this;
        const upload = {
            xhr,
            key: `${names[match[1]]}:${match[2]}`,
            state: 'held',
            release() {
                if (upload.state !== 'held') return;
                upload.state = 'sent';
                send.call(xhr, body);
            },
            progress(loaded) {
                xhr.upload.dispatchEvent(new ProgressEvent('progress', {loaded, total: body.size, lengthComputable: true}));
            },
            fail() {
                if (upload.state !== 'held') return;
                upload.state = 'failed';
                xhr.dispatchEvent(new ProgressEvent('error'));
                xhr.dispatchEvent(new ProgressEvent('loadend'));
            },
        };
        this.controlledUpload = upload;
        window.uploads.push(upload);
        window.activeUploads++;
        window.maxActiveUploads = Math.max(window.maxActiveUploads, window.activeUploads);
        xhr.addEventListener('loadend', () => { window.activeUploads--; }, {once: true});
        if (window.autoReleaseUploads) upload.release();
    };
    XMLHttpRequest.prototype.abort = function() {
        const upload = this.controlledUpload;
        if (upload?.state !== 'held') return abort.call(this);
        upload.state = 'aborted';
        window.abortedUploads++;
        this.dispatchEvent(new ProgressEvent('abort'));
        this.dispatchEvent(new ProgressEvent('loadend'));
    };
    window.finishUploads = () => {
        window.autoReleaseUploads = true;
        [...window.uploads].reverse().forEach(upload => upload.release());
    };
}
JS;
}
