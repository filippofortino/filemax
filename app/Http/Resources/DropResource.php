<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Drop;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Drop */
final class DropResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'instructions' => $this->instructions,
            'url' => route('shared.drops.show', $this->token),
            'open' => $this->isOpen(),
            'closes_at' => $this->closes_at->toIso8601String(),
            'closed_at' => $this->closedAt()->toIso8601String(),
            'closed_early' => $this->closed_at !== null,
            'keep_days' => $this->keep_days,
            'max_file_size' => $this->max_file_size,
            'max_submission_size' => $this->max_submission_size,
            'file_types' => $this->file_types ?? [],
            'extensions' => $this->extensions ?? [],
            'uploads_count' => $this->submissions_count ?? 0,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
