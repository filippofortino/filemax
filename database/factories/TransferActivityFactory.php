<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferActivity;
use App\Models\TransferRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferActivity> */
final class TransferActivityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'transfer_id' => Transfer::factory(),
            'transfer_recipient_id' => fn (array $attributes): string => TransferRecipient::factory()->create(['transfer_id' => $attributes['transfer_id']])->id,
            'kind' => 'opened',
            'created_at' => now(),
        ];
    }
}
