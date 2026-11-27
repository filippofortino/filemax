<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IndexTransfersRequest;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Requests\UpdateTransferSharingRequest;
use App\Http\Resources\TransferResource;
use App\Jobs\PurgeTransfer;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\TransferRecipient;
use App\Services\TransferStorage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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

        return Inertia::render('transfers/create');
    }

    public function store(StoreTransferRequest $request, TransferStorage $storage): JsonResponse
    {
        /** @var array{title?: ?string, message?: ?string, visibility: string, expires_in_days: int, files: list<array{name: string, size: int, type?: ?string, folder?: ?string}>} $data */
        $data = $request->validated();
        $passwordHash = $request->boolean('password_protected') ? Hash::make($request->string('password')->toString()) : null;
        $transfer = DB::transaction(function () use ($request, $data, $storage, $passwordHash): Transfer {
            $transfer = Transfer::query()->create([
                'user_id' => ($request->user() ?? abort(403))->id,
                'organization_id' => $request->string('organization_id')->toString(),
                'title' => $data['title'] ?? null,
                'message' => $data['message'] ?? null,
                'visibility' => $data['visibility'],
                'password_hash' => $passwordHash,
                'expires_in_days' => $data['expires_in_days'],
            ]);
            foreach ($data['files'] as $position => $file) {
                $transfer->files()->create([
                    'original_name' => $file['name'],
                    'folder' => TransferFile::normaliseFolder($file['folder'] ?? null),
                    'path' => 'transfers/'.$transfer->id.'/'.Str::uuid(),
                    'size' => $file['size'],
                    'position' => $position,
                    'part_size' => $storage->partSize((int) $file['size']),
                ]);
            }

            return $transfer;
        });

        return response()->json(['transfer' => new TransferResource($transfer->load(['files', 'organization']))], 201);
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

        $historyQuery = Transfer::query()->where('user_id', $user->id)->whereBelongsTo($user->currentOrganization())->whereNull('drop_id')->where('status', 'ready');
        $totals = [
            'total' => (clone $historyQuery)->count(),
            'active' => $this->applyHistoryStatus(clone $historyQuery, 'active', $now)->count(),
        ];

        $matchingQuery = $this->applyHistoryFilters(clone $historyQuery, $filter, $search);
        $statusCounts = $this->historyStatusCounts($matchingQuery, $now);
        $resultsQuery = $this->applyHistoryStatus(clone $matchingQuery, $status, $now);
        $transfers = $resultsQuery
            ->with(['files', 'organization'])
            ->latest()
            ->latest('id')
            ->paginate(8)
            ->withQueryString();

        if ($transfers->currentPage() > $transfers->lastPage()) {
            return redirect($transfers->url($transfers->lastPage()));
        }

        return Inertia::render('transfers/index', [
            'transfers' => TransferResource::collection($transfers),
            'filter' => $filter,
            'status' => $status,
            'search' => $search,
            'statusCounts' => $statusCounts,
            'totals' => $totals,
            'receivedNew' => Transfer::unopenedCountFor($user),
        ]);
    }

    public function show(Request $request, Transfer $transfer, TransferStorage $storage): Response
    {
        Gate::authorize('view', $transfer);

        return Inertia::render('transfers/show', [
            'transfer' => new TransferResource($transfer->load(['files', 'organization']))->resolve($request),
            'previews' => $transfer->status === 'ready' && $transfer->isAvailable() ? $transfer->files->mapWithKeys(fn (TransferFile $file): array => [$file->id => $storage->previewProps($file)]) : (object) [],
            'membersCount' => $transfer->organization->users()->count(),
            'canUpdate' => Gate::allows('update', $transfer),
            'folderDownloads' => (object) $transfer->archives()->pluck('download_count', 'folder')->all(),
            'people' => fn (): array => $this->people($transfer),
        ]);
    }

    /** Opens a members-only transfer to anyone with the link. There is no way back: the link may already be out. */
    public function update(UpdateTransferSharingRequest $request, Transfer $transfer): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($transfer): void {
            $transfer = Transfer::query()->lockForUpdate()->findOrFail($transfer->id);
            Gate::authorize('update', $transfer);
            abort_unless($transfer->visibility === 'members' && $transfer->revoked_at === null, 422);
            $transfer->update(['visibility' => 'public']);
        });

        if ($request->expectsJson()) {
            return response()->json(['updated' => true]);
        }

        Inertia::flash('toast', ['title' => 'Transfer is now public', 'description' => 'Anyone with the link can download it.']);

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
     * Who opened, previewed or downloaded the transfer, one entry per email address, plus the recipients who haven't opened
     * it yet. A recipient who signed in as a member with the same address is one person.
     *
     * @return list<array{email: string, name: ?string, sent_at: ?string, last_active_at: ?string, activity: list<array{kind: string, file_id: ?string, folder: ?string, times: int, last_at: string}>}>
     */
    private function people(Transfer $transfer): array
    {
        $recipients = $transfer->recipients()->get()->keyBy(fn (TransferRecipient $recipient): string => Str::lower($recipient->email));
        $people = [];

        // shortcut: groups every identified click in PHP; aggregate in SQL once a transfer collects tens of thousands.
        foreach ($transfer->activities()->with(['user:id,name,email', 'recipient:id,email'])->oldest()->get() as $activity) {
            $email = $activity->user->email ?? $activity->recipient?->email;
            if ($email === null) {
                continue;
            }

            $key = Str::lower($email);
            $at = $activity->created_at->toIso8601String();
            $people[$key] ??= ['email' => $email, 'name' => null, 'sent_at' => $recipients->get($key)?->sent_at->toIso8601String(), 'last_active_at' => null, 'activity' => []];
            $people[$key]['name'] ??= $activity->user?->name;
            $people[$key]['last_active_at'] = $at;
            $entry = $activity->kind.'|'.$activity->transfer_file_id.'|'.$activity->folder;
            $times = ($people[$key]['activity'][$entry]['times'] ?? 0) + 1;
            $people[$key]['activity'][$entry] = ['kind' => $activity->kind, 'file_id' => $activity->transfer_file_id, 'folder' => $activity->folder, 'times' => $times, 'last_at' => $at];
        }

        foreach ($recipients as $key => $recipient) {
            $people[$key] ??= ['email' => $recipient->email, 'name' => null, 'sent_at' => $recipient->sent_at->toIso8601String(), 'last_active_at' => null, 'activity' => []];
        }

        $people = array_map(function (array $person): array {
            $activity = array_values($person['activity']);
            usort($activity, fn (array $a, array $b): int => $b['last_at'] <=> $a['last_at']);

            return [...$person, 'activity' => $activity];
        }, array_values($people));
        usort($people, fn (array $a, array $b): int => [$b['last_active_at'] ?? '', $b['sent_at'] ?? ''] <=> [$a['last_active_at'] ?? '', $a['sent_at'] ?? '']);

        return $people;
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    private function applyHistoryFilters(Builder $query, string $filter, string $search): Builder
    {
        if ($filter !== 'all') {
            $query->where('visibility', $filter);
        }

        if ($search !== '') {
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->whereRaw(<<<'SQL'
                LOWER(COALESCE(
                    title,
                    (
                        SELECT COALESCE(folder, original_name)
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
