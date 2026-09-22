<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TransferFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TransferFile */
final class TransferFileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'original_name' => $this->original_name, 'size' => $this->size, 'mime_type' => $this->mime_type, 'status' => $this->status, 'position' => $this->position, 'download_count' => $this->download_count, 'part_size' => $this->part_size];
    }
}
