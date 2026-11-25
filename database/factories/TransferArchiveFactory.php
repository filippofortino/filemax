<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferArchive;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferArchive> */
final class TransferArchiveFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['transfer_id' => Transfer::factory(), 'folder' => 'Photos'];
    }
}
