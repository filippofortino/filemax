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
 * @property-read string|null $wordmark_path
 * @property-read string|null $brand_color
 * @property-read string|null $welcome_message
 * @property-read string|null $footer_text
 * @property-read string|null $footer_url
 * @property-read bool $personal
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Fillable(['name', 'brand_color', 'welcome_message', 'footer_text', 'footer_url'])]
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    use HasUuids;

    public const array ROLES = ['member', 'admin'];

    /** @var array<string, mixed> */
    protected $attributes = ['personal' => false, 'logo_path' => null, 'wordmark_path' => null];

    /** WCAG contrast of a #rrggbb color against white; 4.5 keeps white button text and colored links readable. */
    public static function contrastWithWhite(string $hex): float
    {
        $channel = function (string $part): float {
            $value = hexdec($part) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(mb_substr($hex, 1, 2)) + 0.7152 * $channel(mb_substr($hex, 3, 2)) + 0.0722 * $channel(mb_substr($hex, 5, 2));

        return 1.05 / ($luminance + 0.05);
    }

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

    public function wordmarkUrl(): ?string
    {
        return $this->wordmark_path === null ? null : Storage::temporaryUrl($this->wordmark_path, now()->addHour());
    }

    /**
     * How the pages a recipient opens look for links sent from this organization. Personal links are never branded.
     *
     * @return array{name: string, logo_url: string|null, wordmark_url: string|null, color: string|null, welcome_message: string|null, footer_text: string|null, footer_url: string|null}|null
     */
    public function branding(): ?array
    {
        if ($this->personal) {
            return null;
        }

        return [
            'name' => $this->name,
            'logo_url' => $this->logoUrl(),
            'wordmark_url' => $this->wordmarkUrl(),
            'color' => $this->brand_color,
            'welcome_message' => $this->welcome_message,
            'footer_text' => $this->footer_text,
            'footer_url' => $this->footer_url,
        ];
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['personal' => 'boolean'];
    }
}
