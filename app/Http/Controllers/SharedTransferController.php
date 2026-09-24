<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\TransferFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SharedTransferController
{
    public function show(Request $request, Transfer $transfer): Response
    {
        if (! $transfer->isAvailable()) {
            return Inertia::render('shared/unavailable', [
                'reason' => $transfer->revoked_at ? 'deleted' : ($transfer->expires_at?->lte(now()) ? 'expired' : 'unavailable'),
            ])->toResponse($request)->setStatusCode(410);
        }

        if ($transfer->visibility === 'teams' && ! $request->user()) {
            return redirect()->guest(route('login'));
        }

        if (! Gate::forUser($request->user())->allows('download', $transfer)) {
            $request->session()->put('url.intended', route('shared.show', $transfer->token));

            return Inertia::render('shared/denied', [
                'sender' => $transfer->user()->first(['name', 'email']),
                'token' => $transfer->token,
                'url' => route('shared.show', $transfer->token),
                'own_teams' => $request->user()?->teams()->get(['teams.id', 'teams.name'])->map->only(['id', 'name']) ?? [],
                'requires_verification' => ! $request->user()?->hasVerifiedEmail(),
            ])->toResponse($request)->setStatusCode(403);
        }

        $transfer->whereKey($transfer->id)->whereNull('first_opened_at')->update(['first_opened_at' => now()]);
        $transfer->load(['files', 'user']);

        $response = Inertia::render('shared/show', [
            'transfer' => [
                'id' => $transfer->id,
                'token' => $transfer->token,
                'title' => $transfer->displayTitle(),
                'message' => $transfer->message,
                'sender' => [...$transfer->user->only(['name', 'email']), 'avatar_url' => $transfer->user->avatarUrl()],
                'files' => $transfer->files->map(fn (TransferFile $file): array => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                ]),
                'total_size' => $transfer->files->sum('size'),
                'expires_at' => $transfer->expires_at?->toIso8601String(),
                'visibility' => $transfer->visibility,
            ],
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
