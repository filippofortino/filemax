<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TransferArchiveFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A zip of one folder of a transfer. Its archive_* fields work like the transfer's own, so PrepareTransferArchive treats both alike.
 *
 * @property string $id
 * @property string $transfer_id
 * @property string $folder
 * @property ?string $archive_status
 * @property ?string $archive_path
 * @property int $archive_progress
 * @property ?CarbonInterface $archive_requested_at
 * @property int $download_count
 * @property-read Transfer $transfer
 */
#[Guarded(['id'])]
#[Hidden(['archive_path'])]
final class TransferArchive extends Model
{
    /** @use HasFactory<TransferArchiveFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = ['archive_progress' => 0, 'download_count' => 0];

    public static function storagePath(string $transferId, string $folder): string
    {
        return 'archives/'.$transferId.'/'.hash('sha256', $folder).'.zip';
    }

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['archive_requested_at' => 'datetime', 'archive_progress' => 'integer', 'download_count' => 'integer'];
    }
}
