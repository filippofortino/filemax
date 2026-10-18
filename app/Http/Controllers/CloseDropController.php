<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Drop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

final class CloseDropController
{
    public function __invoke(Drop $drop): RedirectResponse
    {
        Gate::authorize('update', $drop);

        // A Drop past its deadline stays "reached its deadline" rather than "closed by you".
        Drop::query()->whereKey($drop->id)->whereNull('closed_at')->where('closes_at', '>', now())->update(['closed_at' => now()]);

        Inertia::flash('toast', ['title' => 'Drop closed', 'description' => "“{$drop->title}” no longer accepts files. What you’ve received stays in Received."]);

        return back();
    }
}
