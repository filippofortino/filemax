<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Secret;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Secret */
final class SecretResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->isAvailable();

        return [
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
            'available' => $available,
            'expiring_soon' => $available && $this->expires_at->lte(now()->addHours(24)),
            'expires_in' => $available ? $this->expires_at->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : null,
            'reason' => $this->unavailableReason(),
        ];
    }
}
