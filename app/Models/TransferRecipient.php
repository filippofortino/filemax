<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\TransferRecipientFactory;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone the owner emailed the transfer to. Their link carries this id, so what they do with it is credited to them.
 *
 * @property string $id
 * @property string $transfer_id
 * @property string $email
 * @property CarbonInterface $sent_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 * @property-read Transfer $transfer
 */
#[Guarded(['id'])]
final class TransferRecipient extends Model
{
    /** @use HasFactory<TransferRecipientFactory> */
    use HasFactory;

    use HasUuids;

    /** @return BelongsTo<Transfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    /** The personal link in this recipient's email. */
    public function url(): string
    {
        return route('shared.show', ['transfer' => $this->transfer->token, 'recipient' => $this->id]);
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
