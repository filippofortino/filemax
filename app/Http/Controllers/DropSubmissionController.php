<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreDropSubmissionRequest;
use App\Http\Resources\TransferFileResource;
use App\Jobs\PurgeTransfer;
use App\Models\Drop;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Notifications\DropReceived;
use App\Services\TransferStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DropSubmissionController
{
    public function store(StoreDropSubmissionRequest $request, Drop $drop, TransferStorage $storage): JsonResponse
    {
        /** @var array{sender_name: string, message?: ?string, files: list<array{name: string, size: int, type?: ?string}>} $data */
        $data = $request->validated();
        $transfer = DB::transaction(function () use ($drop, $data, $storage): Transfer {
            $transfer = $drop->submissions()->create([
                'user_id' => $drop->user_id,
                'title' => $drop->title,
                'sender_name' => $data['sender_name'],
                'message' => $data['message'] ?? null,
                'visibility' => 'drop',
                'expires_in_days' => $drop->keep_days,
            ]);
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
        $request->session()->put('drop_uploads.'.$transfer->id, true);

        return response()->json(['submission' => ['id' => $transfer->id, 'files' => TransferFileResource::collection($transfer->files)->resolve($request)]], 201);
    }

    /** Sends the submission to the Drop owner once every file has arrived. Safe to retry. */
    public function update(Transfer $transfer, TransferStorage $storage): JsonResponse
    {
        Gate::authorize('submit', $transfer);

        $transfer = $storage->withTransferLock($transfer, function (Transfer $transfer) use ($storage): Transfer {
            abort_if($transfer->revoked_at !== null || $transfer->purged_at !== null, 410, 'This upload was cancelled.');
            if ($transfer->status === 'ready') {
                return $transfer;
            }

            $transfer->load('files');
            if ($transfer->files->isEmpty() || $transfer->files->contains(fn (TransferFile $file): bool => $file->status !== 'ready')) {
                throw ValidationException::withMessages(['files' => 'Upload every file, or remove the ones that failed, before sending.']);
            }

            foreach ($transfer->files as $file) {
                $storage->verify($file);
            }

            $transfer->update(['status' => 'ready', 'received_at' => now(), 'expires_at' => now()->addDays($transfer->expires_in_days)]);

            $owner = $transfer->user;
            if ($owner->settings->notify_drop_received) {
                // Ten emails an hour per Drop; beyond that submissions still arrive, just quietly.
                RateLimiter::attempt('drop-mail:'.$transfer->drop_id, 10, fn () => $owner->notify(new DropReceived($transfer)), 3600);
            }

            return $transfer;
        });

        $drop = $transfer->drop ?? abort(404);

        return response()->json(['sent' => true, 'open' => $drop->isOpen(), 'closes_at' => $drop->closes_at->toIso8601String()]);
    }

    /** Lets the uploader cancel a submission that hasn't been sent yet. */
    public function destroy(Transfer $transfer, TransferStorage $storage): JsonResponse
    {
        Gate::authorize('submit', $transfer);

        $storage->withTransferLock($transfer, function (Transfer $transfer): void {
            abort_unless($transfer->status === 'uploading', 409, 'These files were already sent.');
            if ($transfer->revoked_at === null) {
                $transfer->update(['revoked_at' => now()]);
            }
        });
        dispatch(new PurgeTransfer($transfer->id));

        return response()->json(['cancelled' => true]);
    }
}
