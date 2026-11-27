<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationBrandingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

/** How recipient pages look for links sent from the current organization. */
final class OrganizationBrandingController
{
    public function update(UpdateOrganizationBrandingRequest $request): RedirectResponse
    {
        $organization = ($request->user() ?? abort(403))->currentOrganization();
        $oldPath = $organization->wordmark_path;
        $wordmark = $request->file('wordmark');

        if ($wordmark instanceof UploadedFile) {
            try {
                // WebP keeps the PNG's transparency; 240 px tall stays sharp at the 24 px it is shown, on any screen.
                $path = Image::fromUpload($wordmark)->orient()->scale(1200, 240)->toWebp()->quality(85)->store('logos');
            } catch (Throwable $throwable) {
                report($throwable);
                $path = false;
            }

            if ($path === false) {
                throw ValidationException::withMessages(['wordmark' => 'The full logo could not be saved. Please try another image.']);
            }

            $organization->forceFill(['wordmark_path' => $path]);
        } elseif ($request->boolean('remove_wordmark')) {
            $organization->forceFill(['wordmark_path' => null]);
        }

        // Only the fields sent change, so a request with just a logo keeps the rest.
        foreach (['brand_color', 'welcome_message', 'footer_text', 'footer_url'] as $field) {
            if ($request->has($field)) {
                $organization->setAttribute($field, $request->validated($field));
            }
        }

        if ($organization->brand_color !== null) {
            $organization->setAttribute('brand_color', mb_strtoupper($organization->brand_color));
        }

        $organization->save();

        if ($oldPath !== null && $oldPath !== $organization->wordmark_path) {
            Storage::delete($oldPath);
        }

        Inertia::flash('toast', ['title' => 'Branding saved']);

        return back();
    }
}
