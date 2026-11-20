<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Who a link is sent as. Every user also has a Personal one that only they belong to.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string|null $logo_path
 * @property-read bool $personal
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Fillable(['name'])]
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    public const array ROLES = ['member', 'admin'];

    /** @var array<string, mixed> */
    protected $attributes = ['personal' => false, 'logo_path' => null];

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<OrganizationDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(OrganizationDomain::class);
    }

    /** @return HasMany<OrganizationInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->whereKey($user->id)->exists();
    }

    public function hasAdmin(User $user): bool
    {
        return $this->users()->whereKey($user->id)->wherePivot('role', 'admin')->exists();
    }

    /** The name recipients see next to the sender. Personal links show the sender alone. */
    public function publicName(): ?string
    {
        return $this->personal ? null : $this->name;
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path === null ? null : Storage::temporaryUrl($this->logo_path, now()->addHour());
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['personal' => 'boolean'];
    }
}
