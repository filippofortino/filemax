<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invite is bound to one email and is deleted once accepted.
 *
 * @property-read string $id
 * @property-read string $organization_id
 * @property-read string $email
 * @property-read string $role
 * @property-read string|null $invited_by
 * @property-read CarbonInterface $expires_at
 * @property-read Organization $organization
 * @property-read User|null $inviter
 */
#[Fillable(['email', 'role', 'invited_by', 'expires_at'])]
final class OrganizationInvitation extends Model
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use HasUuids;

    public const int VALID_DAYS = 7;

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Adds the invited person to the organization, puts them in it, and uses up the invite. */
    public function accept(User $user): void
    {
        $this->organization->users()->syncWithoutDetaching([$user->id => ['role' => $this->role]]);
        $user->forceFill(['current_organization_id' => $this->organization_id])->save();
        $this->delete();
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
