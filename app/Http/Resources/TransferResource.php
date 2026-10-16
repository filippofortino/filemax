<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transfer;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transfer */
final class TransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->isAvailable();

        return [
            'id' => $this->id,
            'title' => $this->displayTitle(),
            'message' => $this->message,
            'visibility' => $this->visibility,
            'password_protected' => $this->password_hash !== null,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_in_days' => $this->expires_in_days,
            'first_opened_at' => $this->first_opened_at?->toIso8601String(),
            'download_count' => $this->download_count,
            'last_downloaded_at' => $this->last_downloaded_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'available' => $available,
            'expiring_soon' => $available && $this->expires_at?->lte(now()->addHours(24)) === true,
            'expires_in' => $available ? $this->expires_at?->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE) : null,
            'can_extend' => $this->canExtend(),
            'retained_until' => $this->expires_at?->addDays(30)->toIso8601String(),
            'total_size' => $this->files->sum('size'),
            'files_count' => $this->files->count(),
            'files' => TransferFileResource::collection($this->files)->resolve($request),
            'teams' => $this->teams->map(fn ($team): array => ['id' => $team->id, 'name' => $team->name, 'users_count' => $team->users_count ?? 0]),
            'url' => $this->status === 'ready' ? url('/t/'.$this->token) : null,
            'token' => $this->status === 'ready' ? $this->token : null,
        ];
    }
}
