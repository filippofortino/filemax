<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SecretFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Text encrypted in the sender's browser. Filemax only holds the ciphertext and a hash of a key-derived verifier.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token
 * @property ?string $label
 * @property ?string $payload
 * @property string $verifier_hash
 * @property int $max_views
 * @property int $view_count
 * @property ?CarbonInterface $last_opened_at
 * @property int $wrong_key_count
 * @property ?CarbonInterface $last_wrong_key_at
 * @property CarbonInterface $expires_at
 * @property ?CarbonInterface $revoked_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read User $user
 */
#[Guarded(['id'])]
#[Hidden(['token', 'payload', 'verifier_hash'])]
final class Secret extends Model
{
    /** @use HasFactory<SecretFactory> */
    use HasFactory;

    use HasUuids;

    protected $attributes = ['view_count' => 0, 'wrong_key_count' => 0];

    public static function booted(): void
    {
        self::creating(function (self $secret): void {
            $secret->token ??= Str::random(32);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAvailable(): bool
    {
        return $this->revoked_at === null && $this->view_count < $this->max_views && $this->expires_at->isFuture();
    }

    /** Why the secret can no longer be opened, or null while it still can. */
    public function unavailableReason(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'deleted',
            $this->view_count >= $this->max_views => 'opened',
            ! $this->expires_at->isFuture() => 'expired',
            default => null,
        };
    }

    public function displayLabel(): string
    {
        return $this->label ?? 'Unlabelled secret';
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'max_views' => 'integer',
            'view_count' => 'integer',
            'last_opened_at' => 'datetime',
            'wrong_key_count' => 'integer',
            'last_wrong_key_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
