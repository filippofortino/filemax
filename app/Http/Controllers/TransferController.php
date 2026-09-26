<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferSharingRequest;
use App\Http\Resources\TransferResource;
use App\Jobs\PurgeTransfer;
use App\Models\Team;
use App\Models\Transfer;
use App\Services\TransferStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class TransferController
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', Transfer::class);

        return Inertia::render('transfers/create', ['teams' => ($request->user() ?? abort(403))->teams()->withCount('users')->orderBy('name')->get(['teams.id', 'name'])]);
    }

    public function store(StoreTransferRequest $request, TransferStorage $storage): JsonResponse
    {
        /** @var array{title?: ?string, message?: ?string, visibility: string, expires_in_days: int, team_ids?: list<string>, files: list<array{name: string, size: int, type?: ?string}>} $data */
        $data = $request->validated();
        $transfer = DB::transaction(function () use ($request, $data, $storage): Transfer {
            $transfer = Transfer::query()->create([
                'user_id' => ($request->user() ?? abort(403))->id,
                'title' => $data['title'] ?? null,
                'message' => $data['message'] ?? null,
                'visibility' => $data['visibility'],
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

    public function index(Request $request): Response
    {
        Gate::authorize('create', Transfer::class);
        $query = Transfer::query()->where('user_id', ($request->user() ?? abort(403))->id)->where('status', 'ready');
        $filter = $request->string('filter', 'all')->toString();
        $historyTeams = Team::query()->whereHas('transfers', fn (Builder $query) => $query->where('user_id', $request->user()->id))->orderBy('name')->get(['id', 'name']);
        $totals = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'expired' => (clone $query)->whereNull('revoked_at')->where('expires_at', '<=', now())->count(),
            'downloads' => (int) (clone $query)->sum('download_count'),
        ];
        if ($filter === 'public') {
            $query->where('visibility', 'public');
        } elseif ($filter !== 'all') {
            $query->whereHas('teams', fn (Builder $query) => $query->whereKey($filter));
        }

        return Inertia::render('transfers/index', [
            'transfers' => TransferResource::collection($query->with(['files', 'teams' => fn (Relation $query) => $query->withCount('users')])->latest()->paginate(20)->withQueryString()),
            'teams' => $historyTeams,
            'filter' => $filter,
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
}
