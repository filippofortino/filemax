<?php

declare(strict_types=1);

use App\Models\Drop;
use App\Models\Transfer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

/** @return array<string, mixed> */
function newDropInput(array $overrides = []): array
{
    return [
        'title' => 'Foto e video — Fiera Rimini',
        'instructions' => 'Foto in JPG alla massima risoluzione.',
        'accept_days' => 7,
        'keep_days' => 30,
        'max_file_size' => 2_000_000_000,
        'max_submission_size' => 10_000_000_000,
        'file_types' => ['images', 'videos'],
        'extensions' => [],
        ...$overrides,
    ];
}

it('opens the Drop pages only for verified colleagues', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route($route))->assertRedirect(route('verification.notice'));
    $this->flushSession()->actingAs(User::factory()->create(['email' => 'external@example.com']))->get(route($route))->assertForbidden();
    $this->flushSession()->actingAs(User::factory()->create())->get(route($route))->assertOk();
})->with(['drops.index', 'drops.create', 'received.index']);

it('passes the file type presets to the new Drop form', function (): void {
    $this->actingAs(User::factory()->create())->get(route('drops.create'))->assertInertia(fn (Assert $page): Assert => $page
        ->component('drops/create', false)
        ->where('fileTypes', Drop::FILE_TYPES));
});

it('creates a Drop and returns its link and deadline', function (): void {
    $owner = User::factory()->create();

    $response = $this->actingAs($owner)->postJson(route('drops.store'), newDropInput(['extensions' => ['.INDD', 'zip', ' .zip ']]))
        ->assertCreated()
        ->assertJsonPath('drop.title', 'Foto e video — Fiera Rimini')
        ->assertJsonPath('drop.open', true)
        ->assertJsonPath('drop.closes_at', now()->addDays(7)->toIso8601String())
        ->assertJsonPath('drop.keep_days', 30)
        ->assertJsonPath('drop.extensions', ['indd', 'zip'])
        ->assertJsonMissingPath('drop.token');

    $drop = Drop::query()->sole();
    expect($drop->user_id)->toBe($owner->id)
        ->and($drop->token)->toHaveLength(16)
        ->and($response->json('drop.url'))->toBe(route('shared.drops.show', $drop->token))
        ->and($drop->acceptedExtensions())->toContain('jpg', 'mov', 'indd', 'zip')
        ->and($drop->accepts('Reel.MP4'))->toBeTrue()
        ->and($drop->accepts('notes.pdf'))->toBeFalse();
});

it('accepts any file type up to the Filemax limit when no restrictions are set', function (): void {
    $this->actingAs(User::factory()->create())
        ->postJson(route('drops.store'), newDropInput(['max_file_size' => null, 'max_submission_size' => null, 'file_types' => [], 'extensions' => []]))
        ->assertCreated();

    $drop = Drop::query()->sole();
    expect($drop->acceptedExtensions())->toBe([])
        ->and($drop->accepts('anything.exe'))->toBeTrue()
        ->and($drop->maxFileSize())->toBe(Drop::MAX_FILE_SIZE);
});

it('validates the new Drop', function (array $input, string $field): void {
    $this->actingAs(User::factory()->create())->postJson(route('drops.store'), newDropInput($input))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'missing title' => [['title' => ' '], 'title'],
    'long title' => [['title' => str_repeat('a', 256)], 'title'],
    'long instructions' => [['instructions' => str_repeat('a', 2001)], 'instructions'],
    'unsupported deadline' => [['accept_days' => 3], 'accept_days'],
    'unsupported retention' => [['keep_days' => 1], 'keep_days'],
    'zero file size' => [['max_file_size' => 0], 'max_file_size'],
    'over 100 GB' => [['max_file_size' => Drop::MAX_FILE_SIZE + 1], 'max_file_size'],
    'total under the file size' => [['max_submission_size' => 1_000_000_000], 'max_submission_size'],
    'unknown preset' => [['file_types' => ['archives']], 'file_types.0'],
    'repeated preset' => [['file_types' => ['images', 'images']], 'file_types.0'],
    'bad extension' => [['extensions' => ['tar.gz']], 'extensions.0'],
    'too many extensions' => [['extensions' => array_map(fn (int $index): string => 'x'.$index, range(1, 21))], 'extensions'],
]);

it('lists only the owner’s Drops with status counts and received uploads', function (): void {
    $owner = User::factory()->create();
    $open = Drop::factory()->for($owner)->create(['created_at' => now()->subHour()]);
    $closed = Drop::factory()->for($owner)->closed()->create(['created_at' => now()->subHours(2)]);
    $expired = Drop::factory()->for($owner)->expired()->create(['created_at' => now()->subHours(3)]);
    Drop::factory()->create();
    Transfer::factory()->for($owner)->received()->count(2)->create(['drop_id' => $open->id]);
    Transfer::factory()->for($owner)->received()->uploading()->create(['drop_id' => $open->id]);

    $this->actingAs($owner)->get(route('drops.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('drops/index', false)
        ->where('drops.data', fn ($drops): bool => collect($drops)->pluck('id')->all() === [$open->id, $closed->id, $expired->id])
        ->where('statusCounts', ['all' => 3, 'open' => 1, 'closed' => 2])
        ->where('drops.data.0.uploads_count', 2)
        ->where('drops.data.1.closed_early', true)
        ->where('drops.data.2.closed_early', false)
        ->missing('drops.data.0.token'));

    $this->get(route('drops.index', ['status' => 'closed']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('drops.data', 2)->where('statusCounts.all', 3));
    $this->get(route('drops.index', ['status' => 'everything']))->assertSessionHasErrors('status');
});

it('closes a Drop for its owner only, once', function (): void {
    $drop = Drop::factory()->create(['title' => 'Contratti firmati Q4']);

    $this->actingAs(User::factory()->create())->post(route('drops.close', $drop))->assertForbidden();
    expect($drop->refresh()->closed_at)->toBeNull();

    $this->flushSession()->actingAs($drop->user)->from(route('drops.index'))->post(route('drops.close', $drop))
        ->assertRedirect(route('drops.index'))
        ->assertInertiaFlash('toast.title', 'Drop closed');
    $closedAt = $drop->refresh()->closed_at;
    expect($closedAt?->toDateTimeString())->toBe(now()->toDateTimeString())->and($drop->isOpen())->toBeFalse();

    $this->travel(1)->hour();
    $this->post(route('drops.close', $drop))->assertRedirect();
    expect($drop->refresh()->closed_at?->toDateTimeString())->toBe($closedAt?->toDateTimeString());
});

it('keeps a Drop that reached its deadline from looking closed early', function (): void {
    $drop = Drop::factory()->expired()->create();

    $this->actingAs($drop->user)->post(route('drops.close', $drop))->assertRedirect();

    expect($drop->refresh()->closed_at)->toBeNull();
});
