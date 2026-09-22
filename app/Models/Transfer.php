<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property ?string $title
 * @property ?string $message
 * @property string $visibility
 * @property string $status
 * @property int $expires_in_days
 * @property ?CarbonInterface $expires_at
 * @property ?CarbonInterface $first_opened_at
 * @property int $download_count
 * @property ?CarbonInterface $last_downloaded_at
 * @property ?CarbonInterface $revoked_at
 * @property ?CarbonInterface $purged_at
 * @property ?string $archive_status
 * @property ?string $archive_path
 * @property int $archive_progress
 * @property ?CarbonInterface $archive_requested_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 * @property-read Collection<int, TransferFile> $files
 * @property-read Collection<int, Team> $teams
 */
#[Guarded(['id'])]
#[Hidden(['token', 'archive_path'])]
final class Transfer extends Model
{
    /** @use HasFactory<TransferFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = ['status' => 'uploading', 'visibility' => 'public', 'expires_in_days' => 7, 'download_count' => 0];

    public static function booted(): void
    {
        self::creating(function (self $transfer): void {
            $transfer->token ??= Str::random(64);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<TransferFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(TransferFile::class)->orderBy('position');
    }

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function isAvailable(): bool
    {
        return $this->status === 'ready' && $this->revoked_at === null && $this->purged_at === null && $this->expires_at?->isFuture() === true;
    }

    public function displayTitle(): string
    {
        return $this->title ?? ($this->files->first()->original_name ?? 'Untitled transfer');
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'expires_in_days' => 'integer',
            'expires_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'download_count' => 'integer',
            'last_downloaded_at' => 'datetime',
            'revoked_at' => 'datetime',
            'purged_at' => 'datetime',
            'archive_requested_at' => 'datetime',
            'archive_progress' => 'integer',
        ];
    }
}
