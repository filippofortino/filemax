<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentOrganization
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user() ?? abort(403);

        return DB::transaction(function () use ($request, $next, $actor): Response {
            // Switching and member removal update this same user row.
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);

            if ($request->input('organization_id') !== $user->currentOrganization()->id) {
                $message = 'Your organization changed. Reload the form and check the organization before submitting again.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $message], 409);
                }

                Inertia::flash('toast', ['type' => 'error', 'title' => 'Organization changed', 'description' => $message]);

                return to_route('home');
            }

            $request->setUserResolver(fn (): User => $user);

            return $next($request);
        });
    }
}
