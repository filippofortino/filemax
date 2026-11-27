<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferRecipient> */
final class TransferRecipientFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['transfer_id' => Transfer::factory(), 'email' => fake()->unique()->safeEmail(), 'sent_at' => now()];
    }
}
