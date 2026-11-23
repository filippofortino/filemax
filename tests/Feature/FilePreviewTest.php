<?php

declare(strict_types=1);

use App\Jobs\GenerateFilePreview;
use App\Jobs\PurgeTransfer;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->withoutVite();
});

/** Stores bytes as a ready file the way uploads leave them: without a known type. */
function storedFile(Transfer $transfer, string $name, string $bytes): TransferFile
{
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => $name, 'size' => mb_strlen($bytes, '8bit'), 'mime_type' => 'application/octet-stream']);
    Storage::disk('local')->put($file->path, $bytes);

    return $file;
}

function jpeg(int $width, int $height, bool $cmyk = false): string
{
    $image = new Imagick;
    $image->newImage($width, $height, '#c9b69c');
    if ($cmyk) {
        $image->transformImageColorspace(Imagick::COLORSPACE_CMYK);
    }

    $image->setImageProfile('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"/></x:xmpmeta>');
    $image->setImageFormat('jpeg');

    return $image->getImageBlob();
}

function generatePreview(TransferFile $file): TransferFile
{
    new GenerateFilePreview($file->id)->handle(resolve(TransferStorage::class));

    return $file->refresh();
}

it('makes small WebP copies of an image and keeps no metadata but the colour profile', function (): void {
    $file = generatePreview(storedFile(Transfer::factory()->create(), 'Stand_001.jpg', jpeg(3000, 2000)));

    expect($file->preview_kind)->toBe('image')
        ->and($file->preview_status)->toBe('ready')
        ->and($file->mime_type)->toBe('image/jpeg');

    foreach (['thumbnail' => 640, 'preview' => 2048] as $variant => $edge) {
        $copy = new Imagick;
        $copy->readImageBlob(Storage::disk('local')->get($file->previewPath($variant)));
        expect($copy->getImageFormat())->toBe('WEBP')
            ->and(max($copy->getImageWidth(), $copy->getImageHeight()))->toBe($edge)
            ->and(array_keys($copy->getImageProfiles('*', false)))->not->toContain('xmp');
    }
});

it('converts CMYK images to sRGB for the browser', function (): void {
    $file = generatePreview(storedFile(Transfer::factory()->create(), 'Print.jpg', jpeg(400, 300, cmyk: true)));

    $copy = new Imagick;
    $copy->readImageBlob(Storage::disk('local')->get($file->previewPath('preview')));
    expect($copy->getImageColorspace())->toBe(Imagick::COLORSPACE_SRGB);
});

it('plays video and audio from the original without making copies', function (string $name, string $bytes, string $kind, string $mimeType): void {
    $file = generatePreview(storedFile(Transfer::factory()->create(), $name, $bytes));

    expect($file->preview_kind)->toBe($kind)
        ->and($file->preview_status)->toBe('ready')
        ->and($file->mime_type)->toBe($mimeType)
        ->and(Storage::disk('local')->allFiles($file->previewDirectory()))->toBe([]);
})->with([
    'video' => ['Reel.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64), 'video', 'video/mp4'],
    'audio' => ['Intervista.wav', 'RIFF'.pack('V', 36).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8).'data'.pack('V', 0), 'audio', 'audio/x-wav'],
]);

it('never shows other files in the browser, whatever their name says', function (string $name, string $bytes): void {
    $transfer = Transfer::factory()->create();
    $file = generatePreview(storedFile($transfer, $name, $bytes));

    expect($file->preview_kind)->toBeNull()->and($file->preview_status)->toBe('none');
    $this->postJson(route('shared.files.preview', [$transfer->token, $file]))->assertNotFound();
    $media = URL::temporarySignedRoute('shared.files.media', now()->addMinute(), ['transfer' => $transfer->token, 'file' => $file->id, 'variant' => 'original']);
    $this->get($media)->assertNotFound();
})->with([
    'html' => ['photo.jpg', '<html><body><script>alert(1)</script></body></html>'],
    'svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'broken image' => ['Stand_002.jpg', "\xFF\xD8\xFF\xE0".str_repeat('x', 200)],
]);

it('renders the first page of a PDF and counts its pages', function (): void {
    $pdf = new Imagick;
    $pdf->newImage(200, 300, 'white');
    $pdf->setImageFormat('pdf');
    $page = clone $pdf;
    $pdf->addImage($page);
    $file = generatePreview(storedFile(Transfer::factory()->create(), 'Brochure.pdf', $pdf->getImagesBlob()));

    expect($file->preview_kind)->toBe('pdf')->and($file->page_count)->toBe(2);
    Storage::disk('local')->assertExists($file->previewPath('thumbnail'));
})->skip(fn (): bool => ! is_executable((string) exec('command -v gs')), 'Ghostscript renders PDFs; it is not installed here.');

it('counts opening a preview apart from downloads and emails nobody', function (): void {
    Notification::fake();
    $transfer = Transfer::factory()->create();
    $transfer->user->settings->update(['notify_transfer_downloaded' => true]);
    $file = generatePreview(storedFile($transfer, 'Stand_001.jpg', jpeg(800, 600)));

    $url = $this->postJson(route('shared.files.preview', [$transfer->token, $file]))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('url');
    $this->get($url)->assertOk()
        ->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Disposition', 'inline; filename=Stand_001.jpg');
    expect($this->get($url)->headers->get('Content-Security-Policy'))->toStartWith('sandbox');

    expect($file->refresh()->preview_count)->toBe(1)
        ->and($transfer->refresh()->preview_count)->toBe(1)
        ->and($transfer->last_previewed_at)->not->toBeNull()
        ->and($transfer->download_count)->toBe(0)
        ->and($transfer->first_downloaded_at)->toBeNull();
    Notification::assertNothingSent();
});

it('streams video originals inline with their own type, and PDFs without a sandbox', function (): void {
    $transfer = Transfer::factory()->create();
    $video = generatePreview(storedFile($transfer, 'Reel.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64)));
    $pdf = TransferFile::factory()->for($transfer)->create(['preview_kind' => 'pdf', 'preview_status' => 'ready', 'mime_type' => 'application/pdf']);
    Storage::disk('local')->put($pdf->path, '%PDF-1.4');

    $videoUrl = $this->postJson(route('shared.files.preview', [$transfer->token, $video]))->assertOk()->json('url');
    $response = $this->get($videoUrl)->assertOk()->assertHeader('Content-Type', 'video/mp4');
    expect($response->headers->get('Content-Security-Policy'))->toStartWith('sandbox');

    $pdfUrl = $this->postJson(route('shared.files.preview', [$transfer->token, $pdf]))->assertOk()->json('url');
    $response = $this->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('asks for the same access as a download', function (): void {
    $members = Transfer::factory()->membersOnly()->create();
    $locked = Transfer::factory()->create(['password_hash' => Hash::make('Transfer123')]);
    $revoked = Transfer::factory()->create();

    foreach ([$members, $locked, $revoked] as $transfer) {
        $file = TransferFile::factory()->for($transfer)->create(['preview_kind' => 'image', 'preview_status' => 'ready']);
        $media[] = URL::temporarySignedRoute('shared.files.media', now()->addMinute(), ['transfer' => $transfer->token, 'file' => $file->id, 'variant' => 'thumbnail']);
        $previews[] = route('shared.files.preview', [$transfer->token, $file]);
    }

    $revoked->update(['revoked_at' => now()]);

    $this->postJson($previews[0])->assertForbidden();
    $this->postJson($previews[1])->assertForbidden();
    $this->postJson($previews[2])->assertStatus(410);
    $this->get($media[1])->assertForbidden();
    $this->get($media[2])->assertStatus(410);
    expect(TransferFile::query()->sum('preview_count'))->toBe(0);
});

it('gives the share page each file’s preview and the owner its counts', function (): void {
    $transfer = Transfer::factory()->create();
    $photo = generatePreview(storedFile($transfer, 'Stand_001.jpg', jpeg(800, 600)));
    storedFile($transfer, 'Stand_002.jpg', jpeg(800, 600));
    $this->postJson(route('shared.files.preview', [$transfer->token, $photo]))->assertOk();

    $this->get(route('shared.show', $transfer->token))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfer.files.0.preview.kind', 'image')
        ->where('transfer.files.0.preview.pending', false)
        ->where('transfer.files.0.preview.thumbnail_url', fn (string $url): bool => str_contains($url, '/media/thumbnail'))
        ->where('transfer.files.1.preview.kind', null)
        ->where('transfer.files.1.preview.pending', true)
        ->missing('transfer.preview_count'));

    $this->actingAs($transfer->user)->get(route('transfers.show', $transfer))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('transfer.preview_count', 1)
        ->where('transfer.files.0.preview_count', 1)
        ->where('previews.'.$photo->id.'.kind', 'image'));
});

it('makes previews once each file finishes uploading', function (): void {
    Queue::fake([GenerateFilePreview::class]);
    $transfer = Transfer::factory()->uploading()->create();
    $file = TransferFile::factory()->for($transfer)->create(['status' => 'uploading', 'size' => 5]);
    Storage::disk('local')->put('uploads/'.$transfer->id.'/'.$file->id.'/1', 'hello');

    $this->actingAs($transfer->user)->postJson(route('transfers.uploads.complete', [$transfer, $file]))->assertOk();

    Queue::assertPushed(GenerateFilePreview::class, fn (GenerateFilePreview $job): bool => $job->fileId === $file->id);
});

it('deletes previews with the transfer', function (): void {
    $transfer = Transfer::factory()->create();
    $file = generatePreview(storedFile($transfer, 'Stand_001.jpg', jpeg(800, 600)));
    $transfer->update(['revoked_at' => now()]);

    new PurgeTransfer($transfer->id)->handle(resolve(TransferStorage::class));

    Storage::disk('local')->assertMissing($file->previewPath('thumbnail'));
});
