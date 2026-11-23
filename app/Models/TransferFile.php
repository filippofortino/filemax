<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TransferFileFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $transfer_id
 * @property string $original_name
 * @property string $path
 * @property int $size
 * @property string $mime_type
 * @property string $status
 * @property int $position
 * @property int $download_count
 * @property ?string $upload_id
 * @property int $part_size
 * @property ?string $preview_kind image, pdf, video or audio; null when the file has no preview
 * @property ?string $preview_status null while the preview job hasn't run, then ready or none
 * @property ?int $page_count
 * @property int $preview_count
 * @property-read Transfer $transfer
 */
#[Guarded(['id'])]
#[Hidden(['path', 'upload_id'])]
final class TransferFile extends Model
{
    /** @use HasFactory<TransferFileFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = ['status' => 'uploading', 'mime_type' => 'application/octet-stream', 'position' => 0, 'download_count' => 0, 'part_size' => 5242880];

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function safeName(): string
    {
        $name = basename(str_replace('\\', '/', $this->original_name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return in_array($name, ['', '.', '..'], true) ? 'file' : $name;
    }

    public function previewDirectory(): string
    {
        return 'previews/'.$this->transfer_id.'/'.$this->id;
    }

    /** @param 'thumbnail'|'preview' $variant */
    public function previewPath(string $variant): string
    {
        return $this->previewDirectory().'/'.$variant.'.webp';
    }

    public function partCount(): int
    {
        return max(1, (int) ceil($this->size / $this->part_size));
    }

    public function partBytes(int $part): int
    {
        return min($this->part_size, $this->size - ($part - 1) * $this->part_size);
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['size' => 'integer', 'position' => 'integer', 'download_count' => 'integer', 'part_size' => 'integer', 'page_count' => 'integer', 'preview_count' => 'integer'];
    }
}
