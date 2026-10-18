<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Drop;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SharedDropController
{
    public function show(Request $request, Drop $drop): Response
    {
        // Like transfers and secrets, the owner's name and photo follow their privacy setting.
        $owner = $drop->user->settings->show_name_on_transfers ? $drop->user : null;

        $response = Inertia::render('shared/drop', [
            'drop' => [
                'token' => $drop->token,
                'title' => $drop->title,
                'instructions' => $drop->instructions,
                'owner' => $owner ? ['name' => $owner->name, 'avatar_url' => $owner->avatarUrl()] : null,
                'open' => $drop->isOpen(),
                'closes_at' => $drop->closes_at->toIso8601String(),
                'closed_at' => $drop->closedAt()->toIso8601String(),
                'closed_early' => $drop->closed_at !== null,
                'max_file_size' => $drop->maxFileSize(),
                'max_submission_size' => $drop->max_submission_size,
                'file_types' => $drop->file_types ?? [],
                'extensions' => $drop->extensions ?? [],
                'accepted_extensions' => $drop->acceptedExtensions(),
            ],
        ])->toResponse($request);
        $response->setStatusCode($drop->isOpen() ? 200 : 410);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
