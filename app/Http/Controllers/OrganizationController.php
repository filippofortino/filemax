<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationRequest;
use App\Models\OrganizationDomain;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Settings of the organization the admin is currently working in. */
final class OrganizationController
{
    public function show(Request $request, string $section = 'general'): Response
    {
        $user = $request->user() ?? abort(403);
        $organization = $user->currentOrganization();
        Gate::authorize('update', $organization);

        return Inertia::render('organization/settings', [
            'section' => $section,
            'organization' => [...$organization->only(['id', 'name']), 'logo_url' => $organization->logoUrl()],
            'members' => $section !== 'members' ? [] : $organization->users()->select(['users.*', 'organization_user.role as role'])->orderBy('name')->get()
                ->map(fn (User $member): array => [...$member->only(['id', 'name', 'email']), 'avatar_url' => $member->avatarUrl(), 'role' => $member->getAttribute('role')]),
            'invitations' => $section !== 'members' ? [] : $organization->invitations()->with('inviter:id,name')->latest()->get()
                ->map(fn (OrganizationInvitation $invitation): array => [
                    ...$invitation->only(['id', 'email', 'role']),
                    'invited_by' => $invitation->invited_by === $user->id ? 'you' : $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'expired' => $invitation->expires_at->isPast(),
                ]),
            'domains' => $section !== 'domains' ? [] : $organization->domains()->orderBy('domain')->get()
                ->map(fn (OrganizationDomain $domain): array => [
                    ...$domain->only(['id', 'domain']),
                    'members_count' => $organization->users()->where('email', 'like', '%@'.$domain->domain)->count(),
                ]),
        ]);
    }

    public function update(UpdateOrganizationRequest $request): RedirectResponse
    {
        $organization = ($request->user() ?? abort(403))->currentOrganization();
        $oldPath = $organization->logo_path;
        $logo = $request->file('logo');

        if ($logo instanceof UploadedFile) {
            try {
                $path = Image::fromUpload($logo)->orient()->cover(256, 256)->toWebp()->quality(80)->store('logos');
            } catch (Throwable $throwable) {
                report($throwable);
                $path = false;
            }

            if ($path === false) {
                throw ValidationException::withMessages(['logo' => 'The logo could not be saved. Please try another image.']);
            }

            $organization->forceFill(['logo_path' => $path]);
        } elseif ($request->boolean('remove_logo')) {
            $organization->forceFill(['logo_path' => null]);
        }

        $organization->fill(['name' => $request->string('name')->toString()])->save();

        if ($oldPath !== null && $oldPath !== $organization->logo_path) {
            Storage::delete($oldPath);
        }

        Inertia::flash('toast', ['title' => 'Organization updated']);

        return back();
    }
}
