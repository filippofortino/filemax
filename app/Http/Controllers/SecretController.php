<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexSecretsRequest;
use App\Http\Requests\StoreSecretRequest;
use App\Http\Resources\SecretResource;
use App\Models\Secret;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class SecretController
{
    public function index(IndexSecretsRequest $request): Response|RedirectResponse
    {
        /** @var array{status?: ?string, search?: ?string} $filters */
        $filters = $request->validated();
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $now = now();

        $historyQuery = Secret::query()->whereBelongsTo($request->user() ?? abort(403));
        $totals = [
            'total' => (clone $historyQuery)->count(),
            'active' => $this->applyStatus(clone $historyQuery, 'active', $now)->count(),
        ];

        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $historyQuery->whereRaw("LOWER(COALESCE(label, 'Unlabelled secret')) LIKE LOWER(?) ESCAPE '!'", [$pattern]);
        }

        $statusCounts = array_map(fn (string $option): int => $this->applyStatus(clone $historyQuery, $option, $now)->count(), ['all' => 'all', 'active' => 'active', 'soon' => 'soon', 'expired' => 'expired']);
        $secrets = $this->applyStatus($historyQuery, $status, $now)->latest()->latest('id')->paginate(8)->withQueryString();

        if ($secrets->currentPage() > $secrets->lastPage()) {
            return redirect($secrets->url($secrets->lastPage()));
        }

        return Inertia::render('secrets/index', [
            'secrets' => SecretResource::collection($secrets),
            'status' => $status,
            'search' => $search,
            'statusCounts' => $statusCounts,
            'totals' => $totals,
        ]);
    }

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

        return to_route('secrets.index');
    }

    /**
     * @param  Builder<Secret>  $query
     * @return Builder<Secret>
     */
    private function applyStatus(Builder $query, string $status, CarbonInterface $now): Builder
    {
        if ($status === 'expired') {
            return $query->where(fn (Builder $query) => $query
                ->whereNotNull('revoked_at')
                ->orWhereColumn('view_count', '>=', 'max_views')
                ->orWhere('expires_at', '<=', $now));
        }

        if ($status === 'active' || $status === 'soon') {
            $query->whereNull('revoked_at')->whereColumn('view_count', '<', 'max_views')->where('expires_at', '>', $now);
            if ($status === 'soon') {
                $query->where('expires_at', '<=', $now->copy()->addHours(24));
            }
        }

        return $query;
    }
}
