<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DropFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A link that lets people without an account upload files to its owner. Each submission is a Transfer.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property string $title
 * @property ?string $instructions
 * @property CarbonInterface $closes_at
 * @property ?CarbonInterface $closed_at
 * @property int $keep_days
 * @property ?int $max_file_size
 * @property ?int $max_submission_size
 * @property ?list<string> $file_types
 * @property ?list<string> $extensions
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 * @property-read Collection<int, Transfer> $submissions
 * @property-read ?int $submissions_count
 */
#[Guarded(['id'])]
#[Hidden(['token'])]
final class Drop extends Model
{
    /** @use HasFactory<DropFactory> */
    use HasFactory;

    use HasUuids;

    /** Extensions each preset accepts, lower case and without the dot. */
    public const array FILE_TYPES = [
        'documents' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv'],
        'images' => ['jpg', 'jpeg', 'png', 'heic', 'gif', 'webp', 'svg', 'psd', 'ai'],
        'videos' => ['mp4', 'mov', 'avi', 'webm', 'mkv'],
    ];

    /** Largest file a Drop accepts; an owner can lower it, never raise it. */
    public const int MAX_FILE_SIZE = 100_000_000_000;

    public static function booted(): void
    {
        self::creating(function (self $drop): void {
            $drop->token ??= Str::random(16);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transfer, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Transfer::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->closes_at->isFuture();
    }

    public function closedAt(): CarbonInterface
    {
        return $this->closed_at ?? $this->closes_at;
    }

    /** Uploads already started when the Drop closed may still finish within a day. */
    public function acceptsUploadsUntil(): CarbonInterface
    {
        return $this->closedAt()->addDay();
    }

    /** @return list<string> Empty when any file type is accepted. */
    public function acceptedExtensions(): array
    {
        $extensions = [];
        foreach ($this->file_types ?? [] as $type) {
            $extensions = [...$extensions, ...self::FILE_TYPES[$type] ?? []];
        }

        return array_values(array_unique([...$extensions, ...$this->extensions ?? []]));
    }

    public function accepts(string $name): bool
    {
        $accepted = $this->acceptedExtensions();

        return $accepted === [] || in_array(Str::lower(pathinfo($name, PATHINFO_EXTENSION)), $accepted, true);
    }

    public function maxFileSize(): int
    {
        return $this->max_file_size ?? self::MAX_FILE_SIZE;
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
            'keep_days' => 'integer',
            'max_file_size' => 'integer',
            'max_submission_size' => 'integer',
            'file_types' => 'array',
            'extensions' => 'array',
        ];
    }
}
