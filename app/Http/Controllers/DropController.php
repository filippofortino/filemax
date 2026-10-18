<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexDropsRequest;
use App\Http\Requests\StoreDropRequest;
use App\Http\Resources\DropResource;
use App\Models\Drop;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class DropController
{
    public function index(IndexDropsRequest $request): Response|RedirectResponse
    {
        /** @var array{status?: ?string} $filters */
        $filters = $request->validated();
        $status = $filters['status'] ?? 'all';
        $now = now();

        $query = Drop::query()->whereBelongsTo($request->user() ?? abort(403));
        $statusCounts = array_map(fn (string $option): int => $this->applyStatus(clone $query, $option, $now)->count(), ['all' => 'all', 'open' => 'open', 'closed' => 'closed']);
        $drops = $this->applyStatus($query, $status, $now)
            ->withCount(['submissions' => fn (Builder $submissions) => $submissions->where('status', 'ready')])
            ->latest()
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        if ($drops->currentPage() > $drops->lastPage()) {
            return redirect($drops->url($drops->lastPage()));
        }

        return Inertia::render('drops/index', [
            'drops' => DropResource::collection($drops),
            'status' => $status,
            'statusCounts' => $statusCounts,
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Drop::class);

        return Inertia::render('drops/create', ['fileTypes' => Drop::FILE_TYPES]);
    }

    public function store(StoreDropRequest $request): JsonResponse
    {
        /** @var array{title: string, instructions?: ?string, accept_days: int, keep_days: int, max_file_size?: ?int, max_submission_size?: ?int, file_types?: ?list<string>, extensions?: ?list<string>} $data */
        $data = $request->validated();
        $drop = ($request->user() ?? abort(403))->drops()->create([
            'title' => $data['title'],
            'instructions' => $data['instructions'] ?? null,
            'closes_at' => now()->addDays((int) $data['accept_days']),
            'keep_days' => (int) $data['keep_days'],
            'max_file_size' => $data['max_file_size'] ?? null,
            'max_submission_size' => $data['max_submission_size'] ?? null,
            'file_types' => $data['file_types'] ?? [],
            'extensions' => $data['extensions'] ?? [],
        ]);

        return response()->json(['drop' => new DropResource($drop)], 201);
    }

    /**
     * @param  Builder<Drop>  $query
     * @return Builder<Drop>
     */
    private function applyStatus(Builder $query, string $status, CarbonInterface $now): Builder
    {
        return match ($status) {
            'open' => $query->whereNull('closed_at')->where('closes_at', '>', $now),
            'closed' => $query->where(fn (Builder $closed) => $closed->whereNotNull('closed_at')->orWhere('closes_at', '<=', $now)),
            default => $query,
        };
    }
}
