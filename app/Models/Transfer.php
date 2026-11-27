<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Notifications\TransferDownloaded;
use Carbon\CarbonInterface;
use Database\Factories\TransferFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property ?string $title
 * @property ?string $message
 * @property string $visibility
 * @property ?string $password_hash
 * @property string $status
 * @property int $expires_in_days
 * @property ?CarbonInterface $expires_at
 * @property ?CarbonInterface $first_opened_at
 * @property int $download_count
 * @property ?CarbonInterface $last_downloaded_at
 * @property ?CarbonInterface $first_downloaded_at
 * @property int $preview_count
 * @property ?CarbonInterface $last_previewed_at
 * @property ?CarbonInterface $expiry_reminder_sent_at
 * @property ?CarbonInterface $revoked_at
 * @property ?CarbonInterface $purged_at
 * @property ?string $archive_status
 * @property ?string $archive_path
 * @property int $archive_progress
 * @property ?CarbonInterface $archive_requested_at
 * @property ?string $drop_id
 * @property ?string $sender_name
 * @property ?CarbonInterface $received_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 * @property-read Collection<int, TransferFile> $files
 * @property-read Collection<int, TransferArchive> $archives
 * @property-read Collection<int, TransferRecipient> $recipients
 * @property-read Collection<int, TransferActivity> $activities
 * @property-read ?Drop $drop
 */
#[Guarded(['id'])]
#[Hidden(['token', 'archive_path', 'password_hash'])]
final class Transfer extends Model
{
    use BelongsToOrganization;

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

    /**
     * Submissions that arrived through one of the owner's Drops in the organization they are working in.
     *
     * @return Builder<self>
     */
    public static function receivedBy(User $owner): Builder
    {
        return self::query()->whereBelongsTo($owner)->whereBelongsTo($owner->currentOrganization())->whereNotNull('drop_id')->where('status', 'ready');
    }

    /** Received submissions the owner hasn't opened yet and can still download. */
    public static function unopenedCountFor(User $owner): int
    {
        return self::receivedBy($owner)->whereNull('first_opened_at')->whereNull('revoked_at')->whereNull('purged_at')->where('expires_at', '>', now())->count();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Drop, $this> */
    public function drop(): BelongsTo
    {
        return $this->belongsTo(Drop::class);
    }

    /** @return HasMany<TransferArchive, $this> */
    public function archives(): HasMany
    {
        return $this->hasMany(TransferArchive::class);
    }

    /** Whether any file sits in this folder or below it. */
    public function hasFolder(string $folder): bool
    {
        return $this->files->contains->isIn($folder);
    }

    /** @return HasMany<TransferRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(TransferRecipient::class);
    }

    /** @return HasMany<TransferActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TransferActivity::class);
    }

    /**
     * Credits an open, preview or download to the person behind it: a member of the transfer's organization, or the
     * recipient whose emailed link brought them here. The owner and anyone unidentified only count in the totals.
     *
     * @param  'opened'|'previewed'|'downloaded'  $kind
     */
    public function recordActivity(Request $request, string $kind, ?TransferFile $file = null, ?string $folder = null): void
    {
        $user = $request->user();
        if ($user?->id === $this->user_id) {
            return;
        }

        $memberId = $user !== null && $this->organization->hasMember($user) ? $user->id : null;
        $recipientId = $request->hasSession() ? $request->session()->get('transfer_recipients.'.$this->id) : null;
        if ($memberId === null && ! is_string($recipientId)) {
            return;
        }

        $person = ['user_id' => $memberId, 'transfer_recipient_id' => is_string($recipientId) ? $recipientId : null];
        if ($kind !== 'opened') {
            $this->activities()->create([...$person, 'kind' => $kind, 'transfer_file_id' => $file?->id, 'folder' => $folder]);

            return;
        }

        // A recipient who signs in as the member with their own address is one person, so one open covers both.
        $samePerson = $user !== null && $memberId !== null && $person['transfer_recipient_id'] !== null
            && $this->recipients()->whereKey($person['transfer_recipient_id'])->where('email', $user->email)->exists();
        DB::transaction(function () use ($person, $samePerson): void {
            // The row lock keeps two page loads at once from both recording the first open.
            self::query()->lockForUpdate()->findOrFail($this->id);
            $opened = $this->activities()->where('kind', 'opened')->where(fn (Builder $query): Builder => $samePerson
                ? $query->where('user_id', $person['user_id'])->orWhere('transfer_recipient_id', $person['transfer_recipient_id'])
                : $query->where($person))->exists();

            if (! $opened) {
                $this->activities()->create([...$person, 'kind' => 'opened']);
            }
        });
    }

    /** @return HasMany<TransferFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(TransferFile::class)->orderBy('position')->chaperone();
    }

    public function isAvailable(): bool
    {
        return $this->status === 'ready' && $this->revoked_at === null && $this->purged_at === null && $this->expires_at?->isFuture() === true;
    }

    public function canExtend(): bool
    {
        $now = now();

        return $this->status === 'ready'
            && $this->revoked_at === null
            && $this->purged_at === null
            && $this->expires_at?->gt($now->copy()->subDays(30)) === true
            && $this->expires_at->lte($now->copy()->addDays(7));
    }

    /** Counts a download and emails the owner the first time someone else downloads the transfer. */
    public function recordDownload(?User $downloader): void
    {
        $now = now();
        $isFirst = $this->first_downloaded_at === null && $downloader?->id !== $this->user_id;
        $this->increment('download_count', 1, ['last_downloaded_at' => $now, 'first_downloaded_at' => $isFirst ? $now : $this->first_downloaded_at]);

        if ($isFirst && $this->user->settings->notify_transfer_downloaded) {
            $this->user->notify(new TransferDownloaded($this));
        }
    }

    public function displayTitle(): string
    {
        $first = $this->files->first();

        return $this->title ?? ($first?->folder !== null ? Str::before($first->folder, '/') : ($first->original_name ?? 'Untitled transfer'));
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
            'first_downloaded_at' => 'datetime',
            'preview_count' => 'integer',
            'last_previewed_at' => 'datetime',
            'expiry_reminder_sent_at' => 'datetime',
            'revoked_at' => 'datetime',
            'purged_at' => 'datetime',
            'archive_requested_at' => 'datetime',
            'archive_progress' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
