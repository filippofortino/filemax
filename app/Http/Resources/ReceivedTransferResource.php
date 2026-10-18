<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transfer */
final class ReceivedTransferResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $available = $this->isAvailable();
        $drop = $this->drop;

        return [
            'id' => $this->id,
            'token' => $available ? $this->token : null,
            'sender_name' => $this->sender_name,
            'title' => $this->displayTitle(),
            'message' => $this->message,
            'drop' => $drop === null ? null : [
                'id' => $drop->id,
                'title' => $drop->title,
                'open' => $drop->isOpen(),
                'closes_at' => $drop->closes_at->toIso8601String(),
                'closed_at' => $drop->closedAt()->toIso8601String(),
                'closed_early' => $drop->closed_at !== null,
            ],
            'files' => TransferFileResource::collection($this->files)->resolve($request),
            'files_count' => $this->files->count(),
            'total_size' => $this->files->sum('size'),
            'keep_days' => $this->expires_in_days,
            'received_at' => $this->received_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'available' => $available,
            'gone_at' => $available ? null : ($this->revoked_at ?? $this->expires_at)?->toIso8601String(),
            'is_new' => $available && $this->first_opened_at === null,
        ];
    }
}
