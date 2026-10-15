<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Secret;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Secret */
final class SecretResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'kind' => 'secret',
            'id' => $this->id,
            'title' => $this->displayLabel(),
            'labelled' => $this->label !== null,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
            'max_views' => $this->max_views,
            'view_count' => $this->view_count,
            'last_opened_at' => $this->last_opened_at?->toIso8601String(),
            'wrong_key_count' => $this->wrong_key_count,
            'last_wrong_key_at' => $this->last_wrong_key_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'available' => $this->isAvailable(),
            'reason' => $this->unavailableReason(),
        ];
    }
}
