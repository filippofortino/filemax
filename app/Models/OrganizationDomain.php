<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An email domain whose verified users join the organization without an invite.
 *
 * @property-read string $id
 * @property-read string $organization_id
 * @property-read string $domain
 * @property-read Organization $organization
 */
#[Fillable(['domain'])]
final class OrganizationDomain extends Model
{
    /** @use HasFactory<OrganizationDomainFactory> */
    use HasFactory;

    use HasUuids;

    public static function of(string $email): string
    {
        return Str::afterLast(Str::lower(mb_trim($email)), '@');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
