<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;

/**
 * @property-read string $id
 * @property-read string $name
 * @property-read string $email
 * @property-read string|null $avatar_path
 * @property-read bool $is_admin
 * @property-read string|null $current_organization_id
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read CarbonInterface|null $onboarded_at
 * @property-read string $password
 * @property-read string|null $remember_token
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 * @property-read UserSettings $settings
 */
#[Hidden([
    'password',
    'remember_token',
    'avatar_path',
])]
#[Fillable(['name', 'email', 'password'])]
final class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuids;
    use Notifiable;
    use PasskeyAuthenticatable;

    /** @var array<string, mixed> */
    protected $attributes = ['is_admin' => false, 'avatar_path' => null, 'current_organization_id' => null];

    public static function booted(): void
    {
        self::saving(function (self $user): void {
            $user->setAttribute('email', Str::lower(mb_trim($user->email)));
        });
        self::created(function (self $user): void {
            $user->organizations()->save(new Organization()->forceFill(['name' => 'Personal', 'personal' => true]), ['role' => 'admin']);
        });
    }

    /** @return BelongsToMany<Organization, $this> */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class)->withPivot('role')->withTimestamps();
    }

    /** The organization chosen in the header, or Personal when none is chosen or the user has since left it. */
    public function currentOrganization(): Organization
    {
        return $this->organizations()
            ->where(fn (Builder $query) => $query->whereKey($this->current_organization_id)->orWhere('personal', true))
            ->orderBy('personal')
            ->firstOrFail();
    }

    /** Joins every organization that lists this email's domain. Only call once the email is verified. */
    public function joinOrganizationsByDomain(): void
    {
        $organizations = OrganizationDomain::query()->where('domain', OrganizationDomain::of($this->email))->pluck('organization_id');
        $this->organizations()->syncWithoutDetaching($organizations->all());
    }

    /** @return HasMany<Secret, $this> */
    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class);
    }

    /** @return HasMany<Drop, $this> */
    public function drops(): HasMany
    {
        return $this->hasMany(Drop::class);
    }

    /** @return HasOne<UserSettings, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(UserSettings::class)->withDefault();
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : Storage::temporaryUrl($this->avatar_path, now()->addHour());
    }

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'string',
            'name' => 'string',
            'email' => 'string',
            'avatar_path' => 'string',
            'is_admin' => 'boolean',
            'email_verified_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'password' => 'hashed',
            'remember_token' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
