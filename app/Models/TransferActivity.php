<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TransferActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One open, preview or download by an identified person: an organization member, or a recipient through their emailed link.
 *
 * @property string $id
 * @property string $transfer_id
 * @property ?string $transfer_file_id
 * @property ?string $folder
 * @property 'opened'|'previewed'|'downloaded' $kind
 * @property ?string $user_id
 * @property ?string $transfer_recipient_id
 * @property CarbonInterface $created_at
 * @property-read ?User $user
 * @property-read ?TransferRecipient $recipient
 */
#[Guarded(['id'])]
final class TransferActivity extends Model
{
    /** @use HasFactory<TransferActivityFactory> */
    use HasFactory;

    use HasUuids;

    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<TransferRecipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(TransferRecipient::class, 'transfer_recipient_id');
    }
}
