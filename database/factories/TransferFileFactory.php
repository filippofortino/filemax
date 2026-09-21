<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transfer;
use App\Models\TransferFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TransferFile> */
final class TransferFileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['transfer_id' => Transfer::factory(), 'original_name' => 'document.pdf', 'path' => 'transfers/'.Str::uuid(), 'size' => 4, 'mime_type' => 'application/pdf', 'status' => 'ready'];
    }
}
