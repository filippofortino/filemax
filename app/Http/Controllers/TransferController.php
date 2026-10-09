<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexTransfersRequest;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferSharingRequest;
use App\Http\Resources\TransferResource;
use App\Jobs\PurgeTransfer;
use App\Models\Team;
use App\Models\Transfer;
use App\Services\TransferStorage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class TransferController
{
    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Transfer::class);
        $user = $request->user() ?? abort(403);

        if ($user->onboarded_at === null) {
            $user->forceFill(['onboarded_at' => now()])->save();

            return to_route('welcome');
        }

        return Inertia::render('transfers/create', ['teams' => $user->teams()->withCount('users')->orderBy('name')->get(['teams.id', 'name'])]);
    }

    public function store(StoreTransferRequest $request, TransferStorage $storage): JsonResponse
    {
        /** @var array{title?: ?string, message?: ?string, visibility: string, expires_in_days: int, team_ids?: list<string>, files: list<array{name: string, size: int, type?: ?string}>} $data */
        $data = $request->validated();
        $passwordHash = $request->boolean('password_protected') ? Hash::make($request->string('password')->toString()) : null;
        $transfer = DB::transaction(function () use ($request, $data, $storage, $passwordHash): Transfer {
            $transfer = Transfer::query()->create([
                'user_id' => ($request->user() ?? abort(403))->id,
                'title' => $data['title'] ?? null,
                'message' => $data['message'] ?? null,
                'visibility' => $data['visibility'],
                'password_hash' => $passwordHash,
                'expires_in_days' => $data['expires_in_days'],
            ]);
            $transfer->teams()->sync($data['team_ids'] ?? []);
            foreach ($data['files'] as $position => $file) {
                $transfer->files()->create([
                    'original_name' => $file['name'],
                    'path' => 'transfers/'.$transfer->id.'/'.Str::uuid(),
                    'size' => $file['size'],
                    'position' => $position,
                    'part_size' => $storage->partSize((int) $file['size']),
                ]);
            }

            return $transfer;
        });

        return response()->json(['transfer' => new TransferResource($transfer->load(['files', 'teams' => fn (Relation $query) => $query->withCount('users')]))], 201);
    }

    public function index(IndexTransfersRequest $request): Response|RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        /** @var array{filter?: ?string, status?: ?string, search?: ?string} $filters */
        $filters = $request->validated();
        $filter = $filters['filter'] ?? 'all';
        $status = $filters['status'] ?? 'all';
        $search = $filters['search'] ?? '';
        $now = now();

        $historyQuery = Transfer::query()->where('user_id', $user->id)->where('status', 'ready');
        $totals = [
            'total' => (clone $historyQuery)->count(),
            'active' => $this->applyHistoryStatus(clone $historyQuery, 'active', $now)->count(),
        ];

        $matchingQuery = $this->applyHistoryFilters(clone $historyQuery, $filter, $search);
        $statusCounts = $this->historyStatusCounts($matchingQuery, $now);
        $resultsQuery = $this->applyHistoryStatus(clone $matchingQuery, $status, $now);
        $transfers = $resultsQuery
            ->with(['files', 'teams' => fn (Relation $query) => $query->withCount('users')])
            ->latest()
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        if ($transfers->currentPage() > $transfers->lastPage()) {
            return redirect($transfers->url($transfers->lastPage()));
        }

        $historyTeams = Team::query()
            ->whereHas('transfers', fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('transfers/index', [
            'transfers' => TransferResource::collection($transfers),
            'teams' => $historyTeams,
            'filter' => $filter,
            'status' => $status,
            'search' => $search,
            'statusCounts' => $statusCounts,
            'totals' => $totals,
        ]);
    }

    public function show(Request $request, Transfer $transfer): Response
    {
        Gate::authorize('view', $transfer);

        return Inertia::render('transfers/show', [
            'transfer' => new TransferResource($transfer->load(['files', 'teams' => fn (Relation $query) => $query->withCount('users')]))->resolve($request),
            'teams' => ($request->user() ?? abort(403))->teams()->withCount('users')->orderBy('name')->get(['teams.id', 'name']),
        ]);
    }

    public function update(UpdateTransferSharingRequest $request, Transfer $transfer): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $transfer): void {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            abort_unless($transfer->visibility === 'teams' && $transfer->revoked_at === null, 422);
            /** @var list<string> $teamIds */
            $teamIds = $request->validated('team_ids');
            $transfer->teams()->sync($teamIds);
        });

        if ($request->expectsJson()) {
            return response()->json(['updated' => true]);
        }

        Inertia::flash('toast', ['title' => 'Sharing updated']);

        return back();
    }

    public function destroy(Request $request, Transfer $transfer): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $transfer);
        DB::transaction(function () use ($transfer): void {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->revoked_at === null) {
                $transfer->update(['revoked_at' => now()]);
            }
        });
        dispatch(new PurgeTransfer($transfer->id));

        if ($request->expectsJson()) {
            return response()->json(['revoked' => true]);
        }

        Inertia::flash('toast', ['title' => 'Transfer deleted', 'description' => "The link to “{$transfer->displayTitle()}” no longer works."]);

        return to_route('transfers.index');
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    private function applyHistoryFilters(Builder $query, string $filter, string $search): Builder
    {
        if ($filter === 'public') {
            $query->where('visibility', 'public');
        } elseif ($filter !== 'all') {
            $query->whereHas('teams', fn (Builder $query) => $query->whereKey($filter));
        }

        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->whereRaw(<<<'SQL'
                LOWER(COALESCE(
                    title,
                    (
                        SELECT original_name
                        FROM transfer_files
                        WHERE transfer_files.transfer_id = transfers.id
                        ORDER BY position
                        LIMIT 1
                    ),
                    'Untitled transfer'
                )) LIKE LOWER(?) ESCAPE '!'
                SQL, [$pattern]);
        }

        return $query;
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return array{all: int, active: int, soon: int, expired: int}
     */
    private function historyStatusCounts(Builder $query, CarbonInterface $now): array
    {
        return [
            'all' => (clone $query)->count(),
            'active' => $this->applyHistoryStatus(clone $query, 'active', $now)->count(),
            'soon' => $this->applyHistoryStatus(clone $query, 'soon', $now)->count(),
            'expired' => $this->applyHistoryStatus(clone $query, 'expired', $now)->count(),
        ];
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    private function applyHistoryStatus(Builder $query, string $status, CarbonInterface $now): Builder
    {
        if ($status === 'expired') {
            return $query->where(fn (Builder $query) => $query
                ->whereNotNull('revoked_at')
                ->orWhereNotNull('purged_at')
                ->orWhereNull('expires_at')
                ->orWhere('expires_at', '<=', $now));
        }

        if ($status === 'active' || $status === 'soon') {
            $query->whereNull('revoked_at')->whereNull('purged_at')->where('expires_at', '>', $now);
            if ($status === 'soon') {
                $query->where('expires_at', '<=', $now->copy()->addHours(24));
            }
        }

        return $query;
    }
}
