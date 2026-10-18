<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexReceivedRequest;
use App\Http\Resources\ReceivedTransferResource;
use App\Jobs\PurgeTransfer;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class ReceivedController
{
    public function index(IndexReceivedRequest $request): Response|RedirectResponse
    {
        $user = $request->user() ?? abort(403);
        /** @var array{search?: ?string} $filters */
        $filters = $request->validated();
        $search = $filters['search'] ?? '';

        $query = Transfer::receivedBy($user);
        $totals = ['total' => (clone $query)->count(), 'new' => Transfer::unopenedCountFor($user)];

        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn (Builder $matches) => $matches
                ->whereRaw("LOWER(sender_name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(title) LIKE LOWER(?) ESCAPE '!'", [$pattern]));
        }

        $received = $query->with(['files', 'drop'])->latest('received_at')->latest('id')->paginate(8)->withQueryString();

        if ($received->currentPage() > $received->lastPage()) {
            return redirect($received->url($received->lastPage()));
        }

        return Inertia::render('received/index', [
            'received' => ReceivedTransferResource::collection($received),
            'search' => $search,
            'totals' => $totals,
        ]);
    }

    public function show(Request $request, Transfer $transfer): Response
    {
        Gate::authorize('viewReceived', $transfer);

        Transfer::query()->whereKey($transfer->id)->whereNull('first_opened_at')->update(['first_opened_at' => now()]);

        return Inertia::render('received/show', [
            'transfer' => new ReceivedTransferResource($transfer->load(['files', 'drop']))->resolve($request),
        ]);
    }

    public function destroy(Transfer $transfer): RedirectResponse
    {
        Gate::authorize('viewReceived', $transfer);
        DB::transaction(function () use ($transfer): void {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->revoked_at === null) {
                $transfer->update(['revoked_at' => now()]);
            }
        });
        dispatch(new PurgeTransfer($transfer->id));

        $count = $transfer->files()->count();
        Inertia::flash('toast', ['title' => 'Files deleted', 'description' => "{$transfer->sender_name}’s {$count} ".($count === 1 ? 'file is' : 'files are').' gone.']);

        return to_route('received.index');
    }
}
