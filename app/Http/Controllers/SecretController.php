<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreSecretRequest;
use App\Http\Resources\SecretResource;
use App\Models\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class SecretController
{
    public function create(): Response
    {
        Gate::authorize('create', Secret::class);

        return Inertia::render('secrets/create');
    }

    public function store(StoreSecretRequest $request): JsonResponse
    {
        /** @var array{label?: ?string, payload: string, verifier: string, max_views: int, expires_in_hours: int} $data */
        $data = $request->validated();
        $secret = ($request->user() ?? abort(403))->secrets()->create([
            'label' => $data['label'] ?? null,
            'payload' => $data['payload'],
            'verifier_hash' => hash('sha256', $data['verifier']),
            'max_views' => (int) $data['max_views'],
            'expires_at' => now()->addHours((int) $data['expires_in_hours']),
        ]);

        return response()->json([
            'url' => route('shared.secrets.show', $secret->token),
            'expires_at' => $secret->expires_at->toIso8601String(),
            'max_views' => $secret->max_views,
        ], 201);
    }

    public function show(Request $request, Secret $secret): Response
    {
        Gate::authorize('view', $secret);

        return Inertia::render('secrets/show', ['secret' => new SecretResource($secret)->resolve($request)]);
    }

    public function destroy(Secret $secret): RedirectResponse
    {
        Gate::authorize('delete', $secret);

        if ($secret->isAvailable()) {
            Secret::query()->whereKey($secret->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'payload' => null]);
        }

        Inertia::flash('toast', ['title' => 'Secret deleted', 'description' => "The link to “{$secret->displayLabel()}” no longer works."]);

        return to_route('transfers.index');
    }
}
