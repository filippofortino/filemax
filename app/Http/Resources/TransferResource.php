<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transfer */
final class TransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->displayTitle(),
            'message' => $this->message,
            'visibility' => $this->visibility,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_in_days' => $this->expires_in_days,
            'first_opened_at' => $this->first_opened_at?->toIso8601String(),
            'download_count' => $this->download_count,
            'last_downloaded_at' => $this->last_downloaded_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'available' => $this->isAvailable(),
            'total_size' => $this->files->sum('size'),
            'files_count' => $this->files->count(),
            'files' => TransferFileResource::collection($this->files)->resolve($request),
            'teams' => $this->teams->map(fn ($team): array => ['id' => $team->id, 'name' => $team->name, 'users_count' => $team->users_count ?? 0]),
            'url' => $this->status === 'ready' ? url('/t/'.$this->token) : null,
            'token' => $this->status === 'ready' ? $this->token : null,
        ];
    }
}
