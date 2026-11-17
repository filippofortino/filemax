<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Password;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => ['user' => fn (): ?array => $request->user() ? [
                ...$request->user()->only(['id', 'name', 'email', 'is_admin', 'email_verified_at']),
                'avatar_url' => $request->user()->avatarUrl(),
            ] : null,
                // Personal first, then the organizations the user belongs to; `current` marks the one chosen in the header.
                'organizations' => function () use ($request): array {
                    $user = $request->user();
                    if (! $user instanceof User) {
                        return [];
                    }
                    $current = $user->currentOrganization()->id;

                    return $user->organizations()->select(['organizations.*', 'organization_user.role as role'])->withCount('users')->orderByDesc('personal')->orderBy('name')->get()
                        ->map(fn (Organization $organization): array => [
                            'id' => $organization->id,
                            'name' => $organization->name,
                            'personal' => $organization->personal,
                            'logo_url' => $organization->logoUrl(),
                            'role' => $organization->getAttribute('role'),
                            'members_count' => $organization->users_count,
                            'current' => $organization->id === $current,
                        ])->all();
                },
            ],
            'passwordRequirements' => fn (): array => Arr::only(Password::defaults()->appliedRules(), ['min', 'mixedCase', 'numbers', 'symbols']),
            'status' => fn (): mixed => $request->session()->get('status'),
        ];
    }
}
