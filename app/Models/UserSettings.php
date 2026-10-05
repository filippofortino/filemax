<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserSettingsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string $id
 * @property-read string $user_id
 * @property-read bool $show_name_on_transfers
 * @property-read bool $notify_transfer_expiring
 * @property-read bool $notify_transfer_downloaded
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[Fillable(['show_name_on_transfers', 'notify_transfer_expiring', 'notify_transfer_downloaded'])]
final class UserSettings extends Model
{
    /** @use HasFactory<UserSettingsFactory> */
    use HasFactory;

    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = ['show_name_on_transfers' => true, 'notify_transfer_expiring' => true, 'notify_transfer_downloaded' => false];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'show_name_on_transfers' => 'boolean',
            'notify_transfer_expiring' => 'boolean',
            'notify_transfer_downloaded' => 'boolean',
        ];
    }
}
