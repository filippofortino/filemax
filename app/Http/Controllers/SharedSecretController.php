<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Secret;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SharedSecretController
{
    public function show(Request $request, Secret $secret): Response
    {
        $sender = $secret->user->settings->show_name_on_transfers ? $secret->user : null;

        $response = Inertia::render('shared/secret', [
            'secret' => [
                'token' => $secret->token,
                'sender' => $sender ? ['name' => $sender->name, 'avatar_url' => $sender->avatarUrl()] : null,
                'max_views' => $secret->max_views,
                'views_left' => max(0, $secret->max_views - $secret->view_count),
                'expires_at' => $secret->expires_at->toIso8601String(),
                'last_opened_at' => $secret->last_opened_at?->toIso8601String(),
                'revoked_at' => $secret->revoked_at?->toIso8601String(),
                'available' => $secret->isAvailable(),
                'reason' => $secret->unavailableReason(),
            ],
        ])->toResponse($request);
        $response->setStatusCode($secret->isAvailable() ? 200 : 410);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
