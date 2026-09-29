<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ExtendTransferRequest;
use App\Models\Transfer;
use App\Services\TransferStorage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

final class ExtendTransferController
{
    public function __invoke(ExtendTransferRequest $request, Transfer $transfer, TransferStorage $storage): JsonResponse|RedirectResponse
    {
        try {
            $transfer = $storage->withTransferLock($transfer, function (Transfer $transfer) use ($request): Transfer {
                /** @var string $expectedExpiry */
                $expectedExpiry = $request->validated('expected_expires_at');

                if ($transfer->expires_at?->equalTo($expectedExpiry) !== true) {
                    throw ValidationException::withMessages(['expected_expires_at' => 'This transfer has changed. Refresh the page and try again.']);
                }

                if (! $transfer->canExtend()) {
                    throw ValidationException::withMessages(['expected_expires_at' => 'This transfer cannot be extended. Extensions are available in the final 7 days and for 30 days after expiry, while the files are retained.']);
                }

                $transfer->update(['expires_at' => now()->max($transfer->expires_at)->addDays(7)]);

                return $transfer;
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['expected_expires_at' => 'This transfer is busy. Please try extending it again shortly.']);
        }

        if ($request->expectsJson()) {
            return response()->json(['expires_at' => $transfer->expires_at?->toIso8601String()]);
        }

        return back();
    }
}
