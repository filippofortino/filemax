<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TransferStorageException;
use App\Models\TransferFile;
use App\Services\TransferStorage;
use finfo;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Imagick;
use ImagickException;
use Throwable;

/**
 * Sniffs a finished file's real type and, for images and PDFs, stores a small thumbnail and a larger preview as WebP.
 * Video and audio need no copies: the browser plays the original.
 */
#[Timeout(600)]
#[Tries(3)]
#[UniqueFor(900)]
final class GenerateFilePreview implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Only these types are ever shown in the browser; the ImageMagick coder is named explicitly so a file can't pick its own. */
    public const array KINDS = [
        'image/jpeg' => ['image', 'jpeg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'image/avif' => ['image', 'avif'],
        'image/heic' => ['image', 'heic'],
        'image/heif' => ['image', 'heic'],
        'image/tiff' => ['image', 'tiff'],
        'application/pdf' => ['pdf', 'pdf'],
        'video/mp4' => ['video', null],
        'video/webm' => ['video', null],
        'video/quicktime' => ['video', null],
        'audio/mpeg' => ['audio', null],
        'audio/mp4' => ['audio', null],
        'audio/x-m4a' => ['audio', null],
        'audio/aac' => ['audio', null],
        'audio/wav' => ['audio', null],
        'audio/x-wav' => ['audio', null],
        'audio/ogg' => ['audio', null],
        'audio/flac' => ['audio', null],
        'audio/x-flac' => ['audio', null],
    ];

    /** Bigger sources would strain the worker's memory and temporary disk; they still download as usual. */
    private const int MAX_RENDER_BYTES = 300_000_000;

    public function __construct(public string $fileId) {}

    public function uniqueId(): string
    {
        return $this->fileId;
    }

    public function handle(TransferStorage $storage): void
    {
        $file = TransferFile::query()->with('transfer')->find($this->fileId);

        if (! $file || $file->status !== 'ready' || $file->preview_status !== null || $file->transfer->purged_at !== null) {
            return;
        }

        if (! $storage->disk()->exists($file->path)) {
            $file->update(['preview_status' => 'none']);

            return;
        }

        $mimeType = $this->sniff($storage, $file);
        [$kind, $coder] = self::KINDS[$mimeType] ?? [null, null];
        $pageCount = null;

        if ($coder !== null) {
            try {
                $pageCount = $file->size <= self::MAX_RENDER_BYTES ? $this->render($storage, $file, $coder) : throw new ImagickException('Too large to render.');
            } catch (ImagickException $exception) {
                report($exception);
                $kind = null;
            }
        }

        $file->update(['mime_type' => $mimeType, 'preview_kind' => $kind, 'preview_status' => $kind === null ? 'none' : 'ready', 'page_count' => $pageCount]);

        // shortcut: a purge that ran while this job rendered would leave the copies behind; delete them here instead of locking the transfer for the whole render.
        if ($file->transfer->refresh()->purged_at !== null) {
            $storage->disk()->deleteDirectory($file->previewDirectory());
        }
    }

    public function failed(?Throwable $exception): void
    {
        TransferFile::query()->whereKey($this->fileId)->whereNull('preview_status')->update(['preview_status' => 'none']);
    }

    private function sniff(TransferStorage $storage, TransferFile $file): string
    {
        $stream = $storage->readStream($file);

        try {
            $head = (string) fread($stream, 8192);
        } finally {
            fclose($stream);
        }

        return new finfo(FILEINFO_MIME_TYPE)->buffer($head) ?: 'application/octet-stream';
    }

    /** Writes both copies and returns the page count for a PDF. */
    private function render(TransferStorage $storage, TransferFile $file, string $coder): ?int
    {
        $source = tmpfile();
        $temporary = $source === false ? null : (stream_get_meta_data($source)['uri'] ?? null);
        throw_unless(is_resource($source) && is_string($temporary), TransferStorageException::class, 'Not enough temporary storage to render a preview.');

        try {
            $stream = $storage->readStream($file);

            try {
                throw_if(stream_copy_to_stream($stream, $source) !== $file->size, TransferStorageException::class, 'Could not read the whole file for its preview.');
                fflush($source);
            } finally {
                fclose($stream);
            }

            $path = $coder.':'.$temporary;
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 268435456);
            Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 536870912);

            $image = new Imagick;
            if ($coder === 'pdf') {
                $image->setResolution(144, 144);
            } else {
                $image->setOption('jpeg:size', '4096x4096');
            }

            $image->readImage($path.'[0]');

            if ($coder === 'pdf') {
                $image->setImageBackgroundColor('white');
                $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }

            $image->autoOrient();
            $this->convertToSrgb($image);

            // Drop EXIF (camera, GPS) and keep only the colour profile, which the browser needs to show colours right.
            $profile = $image->getImageProfiles('icc', true)['icc'] ?? null;
            $image->stripImage();
            if (is_string($profile)) {
                $image->profileImage('icc', $profile);
            }

            $image->setImageFormat('webp');
            foreach (['preview' => [2048, 82], 'thumbnail' => [640, 75]] as $variant => [$edge, $quality]) {
                if ($image->getImageWidth() > $edge || $image->getImageHeight() > $edge) {
                    $image->resizeImage($edge, $edge, Imagick::FILTER_LANCZOS, 1, true);
                }

                $image->setImageCompressionQuality($quality);
                throw_unless($storage->disk()->put($file->previewPath($variant), $image->getImageBlob(), ['mimetype' => 'image/webp']), TransferStorageException::class, 'Could not save a preview.');
            }

            if ($coder !== 'pdf') {
                return null;
            }

            $pages = new Imagick;
            $pages->pingImage($path);

            return $pages->getNumberImages();
        } finally {
            fclose($source);
        }
    }

    /**
     * WebP can't hold CMYK, so CMYK gets ImageMagick's approximate conversion. RGB keeps its embedded profile for the browser to apply.
     * shortcut: no ICC-to-sRGB conversion, so CMYK previews' colours are approximate; convert through the file's profile if print work looks off.
     */
    private function convertToSrgb(Imagick $image): void
    {
        if ($image->getImageColorspace() !== Imagick::COLORSPACE_CMYK) {
            return;
        }

        if ($image->getImageProfiles('icc', false) !== []) {
            $image->removeImageProfile('icc');
        }

        $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
    }
}
