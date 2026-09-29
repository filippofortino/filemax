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

    it('replaces the last failed draft file after cleanup without losing transfer details', function (bool $cleanupFails): void {
        $user = User::factory()->create();
        $team = Team::factory()->create(['name' => 'Recovery team']);
        $user->teams()->attach($team);
        $this->actingAs($user);
        $page = visit('/')->assertSee('Browse files');
        $page->fill('#transfer-title', 'Replacement transfer')->fill('#transfer-message', 'Keep this message')
            ->click('Specific teams')->click('button:has-text("Choose teams")')->check('[aria-label="Recovery team"]')
            ->keys('#team-search', 'Escape')->click('30 days');
        $page->script(controlledTransferUploads());
        $page->script('() => window.dropUploadFiles([4])');
        $page->press('Create transfer')->assertScript('window.uploads.length', 1);
        $page->script('() => window.uploads.find(upload => upload.key === "file0.txt:1").fail()');
        $page->assertSee('Some files could not be uploaded.');
        $original = Transfer::query()->sole();
        $originalFileId = $original->files()->sole()->id;

        if ($cleanupFails) {
            $page->script('() => { window.failDelete = true; }');
            $page->press('[aria-label="Remove file0.txt"]')
                ->assertSeeIn('[role="alert"]', 'Filemax is temporarily unavailable. Please try again in a moment.')
                ->assertSee('file0.txt')->assertSee('Retry and create link')
                ->assertScript('window.revocations', 1);
            expect($original->refresh()->revoked_at)->toBeNull()
                ->and($original->files()->sole()->id)->toBe($originalFileId)
                ->and(Transfer::query()->count())->toBe(1);
        }

        $page->press('[aria-label="Remove file0.txt"]')->assertSee('Browse files')
            ->assertDontSee('file0.txt')->assertDontSee('Cancel upload')
            ->assertScript('window.revocations', $cleanupFails ? 2 : 1)
            ->assertScript('window.cancelPrompts.length', 0)
            ->assertScript('document.activeElement.innerText', 'Browse files');
        expect($original->refresh()->revoked_at)->not->toBeNull();
        $page->script('() => { window.autoReleaseUploads = true; window.dropUploadFiles([4], "replacement"); }');
        $page->assertSee('replacement0.txt')->press('Create transfer')
            ->assertVisible('h1:has-text("Your link is ready")')->assertNoJavascriptErrors()
            ->assertScript('window.uploads.map(upload => upload.key)', ['file0.txt:1', 'replacement0.txt:1'])
            ->assertScript('window.fileCompletions', ['replacement0.txt'])
            ->assertScript('window.finalizations', 1);
        $replacement = Transfer::query()->whereNull('revoked_at')->sole();
        expect(Transfer::query()->count())->toBe(2)
            ->and($replacement->id)->not->toBe($original->id)
            ->and($replacement->status)->toBe('ready')
            ->and($replacement->title)->toBe('Replacement transfer')
            ->and($replacement->message)->toBe('Keep this message')
            ->and($replacement->visibility)->toBe('teams')
            ->and($replacement->expires_in_days)->toBe(30)
            ->and($replacement->teams()->sole()->id)->toBe($team->id)
            ->and($replacement->files()->sole()->original_name)->toBe('replacement0.txt');
    })->with(['successful cleanup' => false, 'cleanup retry' => true]);

    it('shows actionable request errors before a draft exists', function (array $failure, string $message): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('window.requestFailure = '.json_encode($failure, JSON_THROW_ON_ERROR));
        $page->script(<<<'JS'
() => {
    const fetch = window.fetch;
    let failed = false;
    window.fetch = async (url, options) => {
        if (!failed && new URL(url, location.href).pathname === '/transfers' && options?.method === 'POST') {
            failed = true;
            const failure = window.requestFailure;
            if (failure.network) throw new TypeError('Failed to fetch');
            return new Response(failure.body, {status: failure.status});
        }
        return fetch(url, options);
    };
    window.dropUploadFiles([4]);
}
JS);
        $page->press('Create transfer')->assertSeeIn('[role="alert"]', $message)
            ->assertSee('file0.txt')->assertMissing('h1:has-text("Your link is ready")')
            ->assertDontSee('Failed to fetch')->assertDontSee('Unauthenticated.')
            ->assertDontSee('CSRF token mismatch.')->assertDontSee('Wrong precedence')
            ->assertDontSee('Private provider diagnostics')->assertDontSee('Service Unavailable')
            ->assertNoJavascriptErrors()->assertScript('window.uploads.length', 0);
        expect(Transfer::query()->count())->toBe(0);

        $page->script('() => { window.autoReleaseUploads = true; }');
        $page->press('Create transfer')->assertVisible('h1:has-text("Your link is ready")')->assertNoJavascriptErrors();
        expect(Transfer::query()->sole()->status)->toBe('ready');
    })->with([
        'network rejection' => [
            ['network' => true],
            'Connection lost. Check your connection and try again.',
        ],
        'expired authentication' => [
            ['status' => 401, 'body' => '{"message":"Unauthenticated."}'],
            'Your session expired. Open Filemax in another tab, sign in if needed, then retry here. Keep this tab open.',
        ],
        'expired CSRF with conflicting validation' => [
            ['status' => 419, 'body' => '{"message":"CSRF token mismatch.","errors":{"email":["Wrong precedence"]}}'],
            'Your session expired. Open Filemax in another tab, sign in if needed, then retry here. Keep this tab open.',
        ],
        'server diagnostics' => [
            ['status' => 500, 'body' => '{"message":"Private provider diagnostics"}'],
            'Filemax is temporarily unavailable. Please try again in a moment.',
        ],
        'HTML server error' => [
            ['status' => 503, 'body' => '<html>Service Unavailable</html>'],
            'Filemax is temporarily unavailable. Please try again in a moment.',
        ],
        'invalid JSON' => [
            ['status' => 400, 'body' => '{invalid'],
            'This request could not be completed. Please try again.',
        ],
        'null body' => [
            ['status' => 400, 'body' => 'null'],
            'This request could not be completed. Please try again.',
        ],
        'malformed errors and message' => [
            ['status' => 400, 'body' => '{"errors":"invalid","message":{"internal":"diagnostic"}}'],
            'This request could not be completed. Please try again.',
        ],
        'validation message' => [
            ['status' => 422, 'body' => '{"errors":{"message":["The message field must not be greater than 5000 characters."]},"message":"Generic validation summary"}'],
            'The message field must not be greater than 5000 characters.',
        ],
    ]);

    it('shows session recovery without failed-file advice after a draft is uploaded', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script(<<<'JS'
() => {
    const fetch = window.fetch;
    let expired = false;
    window.fetch = (url, options) => {
        if (!expired && /\/transfers\/[^/]+\/upload$/.test(new URL(url, location.href).pathname)) {
            expired = true;
            return Promise.resolve(Response.json({message: 'Private session diagnostics'}, {status: 419}));
        }
        return fetch(url, options);
    };
    window.autoReleaseUploads = true;
    window.dropUploadFiles([4]);
}
JS);
        $page->press('Create transfer')
            ->assertSeeIn('[role="alert"]', 'Your session expired. Open Filemax in another tab, sign in if needed, then retry here. Keep this tab open.')
            ->assertDontSee('Retry failed files')
            ->assertDontSee('Keep this tab open until the upload finishes.')
            ->assertDontSee('Private session diagnostics');
        $page->press('Retry and create link')->assertVisible('h1:has-text("Your link is ready")')->assertNoJavascriptErrors();
        expect(Transfer::query()->sole()->status)->toBe('ready');
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
        $page->assertSee($signingFailure ? 'Filemax is temporarily unavailable. Please try again in a moment.' : 'Connection lost.');

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

    it('keeps an upload running when cancellation is declined', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => window.dropUploadFiles([24, 4])');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4);
        $transferId = Transfer::query()->sole()->id;
        $page->script('() => window.uploads.find(upload => upload.key === "file0.txt:1").progress(2)');
        $page->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 7);
        $page->keys('button:has-text("Cancel upload")', 'Enter');

        expect($page->script('() => window.cancelPrompts'))->toBe([
            'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
        ]);
        $page->assertScript('window.abortedUploads', 0)
            ->assertScript('window.revocations', 0)
            ->assertScript('window.activeUploads', 4)
            ->assertScript('window.uploads.length', 4)
            ->assertScript('window.uploads.every(upload => upload.state === "held")')
            ->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 7)
            ->assertVisible('button:has-text("Cancel upload"):focus')
            ->assertSee('file0.txt')->assertSee('file1.txt');
        $transfer = Transfer::query()->sole();
        expect($transfer->id)->toBe($transferId)
            ->and($transfer->status)->toBe('uploading')
            ->and($transfer->revoked_at)->toBeNull()
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $page->script('() => window.finishUploads()');
        $page->assertSee('Your link is ready')->assertScript('window.finalizations', 1)->assertNoJavascriptErrors();
    });

    it('waits for four aborted uploads and draft revocation before allowing another upload', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.allowCancellation = true; window.holdDelete = true; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4)->press('Cancel upload');
        $page->assertScript('window.abortedUploads', 4)->assertScript('Boolean(window.releaseDelete)');
        expect($page->script('() => window.cancelPrompts'))->toBe([
            'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
        ]);
        $page->press('Cancel upload')->assertScript('window.cancelPrompts.length', 1)->assertScript('window.revocations', 1);
        expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
        $page->script('() => window.uploads.forEach(upload => upload.progress(100))');
        $page->assertScript('document.querySelector(`progress[aria-label="Overall upload progress"]`).value', 0);
        expect($page->script('() => window.signedParts.length'))->toBe(4)
            ->and($page->script('() => window.fileCompletions'))->toBe([])
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $page->script('() => window.releaseDelete()');
        $page->assertSee('Drop files here')->assertMissing('[role="alert"]');

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
        $page->script('() => { window.allowCancellation = true; window.failDelete = true; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4)->press('Cancel upload');
        $page->assertSee('Filemax is temporarily unavailable. Please try again in a moment.')->assertSee('file0.txt')->assertSee('file1.txt')
            ->assertDontSee('Retry failed files')
            ->assertDontSee('Keep this tab open until the upload finishes.');
        expect(Transfer::query()->sole()->revoked_at)->toBeNull()
            ->and($page->script('() => window.abortedUploads'))->toBe(4)
            ->and($page->script('() => window.finalizations'))->toBe(0);
        $transferId = Transfer::query()->sole()->id;
        $page->script('() => { window.allowCancellation = false; }');
        $page->keys('button:has-text("Cancel upload")', 'Enter')
            ->assertSee('Filemax is temporarily unavailable. Please try again in a moment.')
            ->assertSee('file0.txt')->assertSee('file1.txt')
            ->assertVisible('button:has-text("Cancel upload"):focus')
            ->assertScript('window.abortedUploads', 4)
            ->assertScript('window.revocations', 1)
            ->assertScript('window.finalizations', 0)
            ->assertScript('window.cancelPrompts.length', 2);
        expect(Transfer::query()->sole()->id)->toBe($transferId)
            ->and(Transfer::query()->sole()->revoked_at)->toBeNull();
        $page->script('() => { window.allowCancellation = true; }');
        $page->press('Cancel upload')->assertSee('Drop files here')->assertNoJavascriptErrors();
        $page->assertScript('window.revocations', 2);
        expect($page->script('() => window.cancelPrompts'))->toBe(array_fill(0, 3,
            'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
        ));
        expect(Transfer::query()->sole()->revoked_at)->not->toBeNull();
    });

    it('waits for an aborted signing response without starting its upload', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { window.allowCancellation = true; window.holdSigning = "file0.txt:2"; window.dropUploadFiles([24, 4]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 3)->assertScript('Boolean(window.releaseSigning)')->press('Cancel upload');
        $page->assertScript('window.abortedUploads', 3)->assertScript('window.revocations', 0);
        expect($page->script('() => window.cancelPrompts'))->toBe([
            'Cancel this upload? Uploaded progress will be discarded. You will need to select and upload the files again.',
        ]);
        expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
        $page->script('() => window.releaseSigning()');
        $page->assertSee('Drop files here')->assertMissing('[role="alert"]')->assertNoJavascriptErrors();
        expect($page->script('() => window.uploads.length'))->toBe(3)
            ->and($page->script('() => window.signedParts.length'))->toBe(4)
            ->and($page->script('() => window.fileCompletions'))->toBe([])
            ->and($page->script('() => window.finalizations'))->toBe(0)
            ->and(Transfer::query()->sole()->revoked_at)->not->toBeNull();
    });

    it('pluralizes the time left', function (): void {
        $page = visit('/')->assertSee('Browse files');
        $page->script(controlledTransferUploads());
        $page->script('() => { const now = Date.now; window.clockOffset = 0; Date.now = () => now() + window.clockOffset; window.dropUploadFiles([4, 4, 4, 4, 1]); }');
        $page->press('Create transfer')->assertScript('window.uploads.length', 4);

        foreach ([10 => 'About 1 second left', 26 => 'About 2 seconds left', 954 => 'About 1 minute left', 1400 => 'About 2 minutes left'] as $offset => $timeLeft) {
            $page->script("() => { window.clockOffset = {$offset} * 1000; window.uploads.forEach(upload => upload.progress(4)); }");
            $page->assertSee($timeLeft);
        }
        $page->assertNoJavascriptErrors();
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

it('creates a transfer when pressing near the right edge of the shrinking submit button', function (string $reducedMotion): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/', ['reducedMotion' => $reducedMotion])->resize(1280, 940)->assertSee('Drop files here');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer();
    data.items.add(new File(['hello'], 'edge.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
}
JS);
    $page->assertSee('edge.txt');
    $width = $page->script(<<<'JS'
() => {
    const button = document.querySelector('button[type=submit]');
    window.pressedWidth = button.getBoundingClientRect().width;
    button.addEventListener('mousedown', () => {
        let frame;
        const measure = () => {
            window.pressedWidth = Math.min(window.pressedWidth, button.getBoundingClientRect().width);
            frame = requestAnimationFrame(measure);
        };
        measure();
        document.addEventListener('mouseup', () => cancelAnimationFrame(frame), {capture: true, once: true});
    }, {once: true});
    return button.getBoundingClientRect().width;
}
JS);

    $page->page()->locator('button[type=submit]')->click([
        'position' => ['x' => $width - 4, 'y' => 24],
        'delay' => 100,
        'force' => true,
    ]);

    expect($page->script('() => window.pressedWidth'))->toBeLessThan($width);
    $page->assertVisible('h1:has-text("Your link is ready")')->assertNoJavascriptErrors();
})->with(['no-preference', 'reduce']);

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
    $page->click('Specific teams')->click('button:has-text("Choose teams")')->check('[aria-label="Mediamax"]')->keys('#team-search', 'Escape');
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
    $page->assertSee('Keep this tab open until the upload finishes.');
    $page->screenshot(filename: 'upload-progress');
    $page->script('() => new Promise(resolve => { const timer = setInterval(() => { if (window.failUpload) { clearInterval(timer); window.failUpload(); resolve(true); } }, 20); })');
    $page->assertSee('Upload incomplete')->assertSee('Some files could not be uploaded.');
    expect($page->script('() => (document.body.innerText.match(/safe/g) ?? []).length'))->toBe(1);
    $page->assertSeeIn('[role="alert"]', 'Completed files are safe — retry sends only what’s missing, or remove the failed files to finish with the rest.')
        ->assertDontSee('Retry failed files')
        ->assertDontSee('Keep this tab open until the upload finishes.');
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
    $page->click('My transfers')->assertSee('1 transfer · 1 active · 0 expired')->assertSee('Spot autunno');
    $page->screenshot(filename: 'transfer-history');
});

it('wraps a long filename and message in the draft summary at 320px', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $name = 'Lenergy_Spot30s_v3_FINAL_approvato_cliente_versione_definitiva_2026.mp4';
    $link = 'https://drive.example.com/materiali/Lenergy_Spot30s_v3_FINAL_approvato_cliente_versione_definitiva_2026';
    $page = visit('/')->resize(320, 900)->assertSee('Drop files here');
    $page->fill('#transfer-message', $link);
    $page->script(<<<JS
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], '{$name}'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) { this.dispatchEvent(new ProgressEvent('error')); return; }
        return send.call(this, body);
    };
}
JS);
    $page->press('Create transfer')->assertSee('Retry and create link');

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue()
        ->and($page->script('() => { const expected = '.json_encode([$name, $link], JSON_THROW_ON_ERROR).'; const boxes = [...document.querySelectorAll("form div.rounded-md.border.bg-muted")].filter(box => expected.includes(box.textContent.trim())); return boxes.map(box => box.scrollWidth <= box.clientWidth); }'))->toBe([true, true]);
    $page->assertNoJavascriptErrors();
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
    $page = visit('/')->click('Specific teams')->click('button:has-text("Choose teams")')->check('[aria-label="Old team"]')->keys('#team-search', 'Escape');
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
    $page->click('button:has-text("Choose teams")')->check('[aria-label="New team"]')->keys('#team-search', 'Escape')->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->teams()->sole()->id)->toBe($newTeam->id);
    expect($page->script('() => window.uploadPutCount'))->toBe(1);
    expect($page->script('() => window.leavePrompts'))->toBe(0);
});

it('keeps keyboard focus on the nearest file control after a remove or cancel', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(controlledTransferUploads());
    $page->script('() => window.dropUploadFiles([1, 2, 3])');

    $page->keys('[aria-label="Remove file1.txt"]', 'Enter')
        ->assertVisible('[aria-label="Remove file2.txt"]:focus')
        ->keys(':focus', 'Enter')
        ->assertVisible('[aria-label="Remove file0.txt"]:focus')
        ->keys(':focus', 'Enter')
        ->assertVisible('button:has-text("Browse files"):focus');

    $page->script('() => { window.allowCancellation = true; window.dropUploadFiles([4]); }');
    $page->keys('button[type=submit]', 'Enter')->assertScript('window.uploads.length', 1);
    $page->keys('button:has-text("Cancel upload")', 'Enter')
        ->assertVisible('button:has-text("Browse files"):focus')
        ->assertNoJavascriptErrors();
});

it('keeps keyboard focus on the upload status through a failure and moves it to the ready heading', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(controlledTransferUploads());
    $page->script('() => window.dropUploadFiles([1, 2])');

    $page->keys('button[type=submit]', 'Enter')
        ->assertScript('window.uploads.length', 2)
        ->assertVisible('h1:focus')
        ->assertScript('document.activeElement.closest("[aria-live], [role=status], [role=alert]") === null');

    $page->script('() => { window.uploads.find(upload => upload.key === "file1.txt:1").fail(); window.uploads.find(upload => upload.key === "file0.txt:1").release(); }');
    $page->assertScript('document.querySelector("main [role=alert]")?.innerText.trim().length > 0')
        ->assertVisible('h1:focus');

    $page->keys('[aria-label="Remove file1.txt"]', 'Enter')
        ->assertVisible('h1:focus')
        ->keys('button[type=submit]', 'Enter')
        ->assertVisible('h1:has-text("Your link is ready"):focus')
        ->assertNoJavascriptErrors();
});

it('leaves deliberate header focus in place when an upload completes', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(controlledTransferUploads());
    $page->script('() => window.dropUploadFiles([4])');
    $page->keys('button[type=submit]', 'Enter')->assertScript('window.uploads.length', 1);
    $page->script('() => document.querySelector(`header nav a[href="/transfers"]`).focus()');
    $page->script('() => window.uploads[0].release()');

    $page->assertVisible('h1:has-text("Your link is ready")')
        ->assertVisible('header nav a:has-text("My transfers"):focus')
        ->assertNoJavascriptErrors();
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
    $page->assertSee('5 files · 25 B')->press('Create transfer')->assertSee('Your link is ready')
        ->assertSee('5 files · 25 B · expires')
        ->assertSee('file3.txt')
        ->assertDontSee('file4.txt')
        ->press('Show all 5 files')
        ->assertSee('file4.txt')
        ->press('Show fewer files')
        ->assertDontSee('file4.txt')
        ->assertNoJavascriptErrors();
});

it('pluralizes file and member counts from upload to transfer detail', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $user = User::factory()->create();
    $mediamax = Team::factory()->create(['name' => 'Mediamax']);
    $lenergy = Team::factory()->create(['name' => 'Lenergy']);
    $user->teams()->attach([$mediamax->id, $lenergy->id]);
    $lenergy->users()->attach(User::factory()->create());
    $this->actingAs($user);
    $page = visit('/')->assertSee('Drop files here');
    $page->click('Specific teams')->click('button:has-text("Choose teams")')
        ->assertSee('2 members')->assertSee('1 member')->assertDontSee('1 members')
        ->check('[aria-label="Mediamax"]')->check('[aria-label="Lenergy"]')->keys('#team-search', 'Escape');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'brief.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
}
JS);
    $page->assertSee('1 file · 5 B')->press('Create transfer')->assertSee('1 file · 5 B · expires');
    $page->click('View transfer')->assertSee('2 members')->assertSee('1 member')->assertDontSee('1 members')->assertNoJavascriptErrors();
});

it('keeps every upload form placeholder at 4.5:1 contrast and lighter than typed text', function (): void {
    $user = User::factory()->create();
    $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
    $this->actingAs($user);
    $page = visit('/')->assertSee('Drop files here');
    $page->click('Specific teams')->click('button:has-text("Choose teams")');
    $page->page()->locator('#team-search')->waitFor();

    $contrast = $page->script(<<<'JS'
() => {
    const paint = (...colors) => {
        const context = document.createElement('canvas').getContext('2d');
        for (const color of ['#fff', ...colors]) {
            context.fillStyle = color;
            context.fillRect(0, 0, 1, 1);
        }
        return [...context.getImageData(0, 0, 1, 1).data.slice(0, 3)];
    };
    const luminance = (rgb) => {
        const [r, g, b] = rgb.map((value) => value / 255).map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };
    const ratio = (a, b) => {
        const [lighter, darker] = [luminance(a), luminance(b)].sort((x, y) => y - x);
        return (lighter + 0.05) / (darker + 0.05);
    };
    return Object.fromEntries([...document.querySelectorAll('[placeholder]')].map((field) => {
        const style = getComputedStyle(field);
        const background = paint(style.backgroundColor);
        return [field.id, {
            placeholder: ratio(paint(style.backgroundColor, getComputedStyle(field, '::placeholder').color), background),
            text: ratio(paint(style.backgroundColor, style.color), background),
        }];
    }));
}
JS);

    expect($contrast)->toHaveKeys(['transfer-title', 'transfer-message', 'team-search']);
    foreach ($contrast as $field => $ratios) {
        expect($ratios['placeholder'])
            ->toBeGreaterThanOrEqual(4.5, "#{$field} placeholder contrast")
            ->toBeLessThan($ratios['text'], "#{$field} placeholder should stay lighter than typed text");
    }
});

it('tabs from the header straight to Browse files, which still opens the file chooser', function (): void {
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');

    $page->keys('main button:has-text("Browse files")', 'Shift+Tab')
        ->assertScript('Boolean(document.activeElement.closest("header"))')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.innerText', 'Browse files');

    $page->script(<<<'JS'
() => {
    window.fileChoosers = 0;
    HTMLInputElement.prototype.click = function () {
        if (this.type === 'file') window.fileChoosers++;
    };
}
JS);
    $page->keys(':focus', 'Enter')
        ->assertScript('window.fileChoosers', 1)
        ->assertNoJavascriptErrors();
});

it('keeps Create transfer in the tab order and sends focus to Browse files when no file is added', function (): void {
    $this->actingAs(User::factory()->create());
    $description = 'document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText';
    $hintColor = '() => getComputedStyle(document.getElementById("submit-hint")).color';

    $page = visit('/')->assertSee('Browse files')
        ->keys('input[name=expiry]:checked', 'Tab')
        ->assertScript('document.activeElement.innerText', 'Create transfer')
        ->assertScript($description, 'Add at least one file to continue');
    $hintColorBefore = $page->script($hintColor);

    $page->keys(':focus', 'Enter')
        ->assertScript('document.activeElement.innerText', 'Browse files')
        ->assertScript($description, 'Add at least one file to continue')
        ->assertNoJavascriptErrors();

    expect($page->script($hintColor))->not->toBe($hintColorBefore)
        ->and(Transfer::query()->count())->toBe(0);
});

it('sends focus to the team picker when no team is selected, before and after an interrupted upload', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $user = User::factory()->create();
    $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
    $this->actingAs($user);
    $description = 'document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText';

    $page = visit('/')->click('Specific teams');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'teams.txt'));
    document.querySelector('main').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    let interrupted = false;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob && !interrupted) {
            interrupted = true;
            this.dispatchEvent(new ProgressEvent('error'));
            return;
        }
        return send.call(this, body);
    };
}
JS);
    $page->assertSee('teams.txt')->press('Create transfer')
        ->assertScript('document.activeElement.innerText.startsWith("Choose teams")')
        ->assertScript($description, 'Select at least one team.');
    expect(Transfer::query()->count())->toBe(0);

    $page->keys(':focus', 'Enter')->check('[aria-label="Mediamax"]')->keys('#team-search', 'Escape')
        ->press('Create transfer')->assertSee('Retry and create link');
    $transferId = Transfer::query()->sole()->id;

    $page->click('[aria-label="Remove Mediamax"]')->press('Retry and create link')
        ->assertScript('document.activeElement.innerText.startsWith("Choose teams")')
        ->assertScript($description, 'Select at least one team.')
        ->assertSee('teams.txt')
        ->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->id)->toBe($transferId);

    $page->keys(':focus', 'Enter')->check('[aria-label="Mediamax"]')->keys('#team-search', 'Escape')
        ->press('Retry and create link')->assertVisible('h1:has-text("Your link is ready")')
        ->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->id)->toBe($transferId);
});

it('names the account menu and team picker after their visible text', function (): void {
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $user->teams()->attach(Team::factory()->create(['name' => 'Mediamax']));
    $this->actingAs($user);

    visit('/')->resize(1280, 940)
        ->assertSeeIn('header [data-slot="popover-trigger"]', 'Filippo')
        ->assertVisible('internal:role=button[name="Filippo Fortino, account menu"s]')
        ->click('Specific teams')
        ->assertVisible('internal:role=button[name="Choose teams Select at least one"s]')
        ->resize(390, 844)
        ->assertVisible('internal:role=button[name="Filippo Fortino, account menu"s]')
        ->assertVisible('internal:role=button[name="Choose teams Select at least one"s]')
        ->click('button:has-text("Choose teams")')
        ->check('[aria-label="Mediamax"]')
        ->keys('#team-search', 'Escape')
        ->assertVisible('internal:role=button[name="Choose teams 1 selected"s]')
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
    window.cancelPrompts = [];
    window.allowCancellation = false;
    window.confirm = (message) => {
        if (message.startsWith('Leave this upload?')) {
            window.leavePrompts++;
            return false;
        }
        window.cancelPrompts.push(message);
        return window.allowCancellation;
    };
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
