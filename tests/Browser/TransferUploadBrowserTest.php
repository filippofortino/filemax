<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

it('recovers a failed multi-file upload while preserving finished files and sharing', function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $team = Team::factory()->create(['name' => 'Mediamax']);
    $user->teams()->attach($team);
    $this->actingAs($user);
    $page = visit('/')->resize(1280, 940)->assertSee('Drop files here');
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
    document.querySelector('.upload-grid').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const original = XMLHttpRequest.prototype.send;
    let sent = 0;
    window.uploadPutCount = 0;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) {
            window.uploadPutCount++;
            sent++;
            if (sent === 2) { window.failUpload = () => this.dispatchEvent(new ProgressEvent('error')); return; }
        }
        return original.call(this, body);
    };
}
JS);
    $page->assertSee('Lenergy_Spot30s_v3.mp4')->screenshot(filename: 'upload-teams-selected');
    $page->press('Create transfer')->assertSee('Uploading…');
    $page->screenshot(filename: 'upload-progress');
    $page->script('() => new Promise(resolve => { const timer = setInterval(() => { if (window.failUpload) { clearInterval(timer); window.failUpload(); resolve(true); } }, 20); })');
    $page->assertSee('A little interruption')->assertSee('Some files could not be uploaded.');
    $page->screenshot(filename: 'upload-failed');
    expect(Transfer::query()->sole()->status)->toBe('uploading');
    $page->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
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

it('keeps upload cancellation disabled until the draft has been revoked', function (): void {
    Storage::fake('local');
    $this->actingAs(User::factory()->create());
    $page = visit('/')->assertSee('Browse files');
    $page->script(<<<'JS'
() => {
    const data = new DataTransfer(); data.items.add(new File(['hello'], 'cancel.txt'));
    document.querySelector('.upload-grid').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body) { if (body instanceof Blob) return; return send.call(this, body); };
    const fetch = window.fetch;
    window.fetch = async (url, options) => {
        if (options?.method === 'DELETE') { await new Promise(resolve => { window.releaseDelete = resolve; }); }
        return fetch(url, options);
    };
}
JS);
    $page->press('Create transfer')->assertSee('Cancel upload')->press('Cancel upload');
    expect($page->script('() => document.querySelector("button[type=submit]").disabled'))->toBeTrue();
    $page->script('() => window.releaseDelete()');
    $page->assertSee('Drop files here')->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->revoked_at)->not->toBeNull();
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
    document.querySelector('.upload-grid').dispatchEvent(new DragEvent('drop', {bubbles: true, dataTransfer: data}));
    const send = XMLHttpRequest.prototype.send;
    window.uploadPutCount = 0;
    XMLHttpRequest.prototype.send = function(body) {
        if (body instanceof Blob) { window.uploadPutCount++; window.resumeUpload = () => send.call(this, body); return; }
        return send.call(this, body);
    };
}
JS);
    $page->press('Create transfer')->assertSee('Uploading…');
    $user->teams()->detach($oldTeam);
    $page->script('() => new Promise(resolve => { const timer = setInterval(() => { if (window.resumeUpload) { clearInterval(timer); window.resumeUpload(); resolve(true); } }, 20); })');
    $page->assertSee('Ready to finish')->assertSee('Select at least one');
    $page->click('[aria-label="Choose teams"]')->check('[aria-label="New team"]')->keys('#team-search', 'Escape')->press('Retry and create link')->assertSee('Your link is ready')->assertNoJavascriptErrors();
    expect(Transfer::query()->sole()->teams()->sole()->id)->toBe($newTeam->id);
    expect($page->script('() => window.uploadPutCount'))->toBe(1);
});
