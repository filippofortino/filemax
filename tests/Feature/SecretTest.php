<?php

declare(strict_types=1);

use App\Models\Secret;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

/** @return array<string, mixed> */
function newSecretInput(array $overrides = []): array
{
    return [
        'label' => 'Token Google Ads (dev)',
        'payload' => base64_encode(random_bytes(80)),
        'verifier' => str_repeat('V', 43),
        'max_views' => 1,
        'expires_in_hours' => 24,
        ...$overrides,
    ];
}

function secretWithVerifier(string $verifier, array $attributes = []): Secret
{
    return Secret::factory()->create([...$attributes, 'verifier_hash' => hash('sha256', $verifier)]);
}

it('opens the new secret page only for verified colleagues', function (): void {
    $this->get(route('secrets.index'))->assertRedirect(route('login'));
    $this->get(route('secrets.create'))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->unverified()->create())->get(route('secrets.create'))->assertRedirect(route('verification.notice'));
    $this->flushSession()->actingAs(User::factory()->create(['email' => 'external@example.com']))->get(route('secrets.create'))->assertForbidden();
    $this->flushSession()->actingAs(User::factory()->create())->get(route('secrets.create'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('secrets/create', false));
});

it('lists only the owner’s secrets, newest first, with label search and status counts', function (): void {
    $owner = User::factory()->create();
    $later = Secret::factory()->for($owner)->create(['label' => 'Token Google Ads (dev)', 'expires_at' => now()->addDays(3), 'created_at' => now()->subHours(3)]);
    $soon = Secret::factory()->for($owner)->create(['label' => null, 'expires_at' => now()->addMinutes(30), 'created_at' => now()->subHours(2)]);
    $opened = Secret::factory()->for($owner)->opened()->create(['label' => 'Wi-Fi ospiti', 'created_at' => now()->subHour()]);
    $deleted = Secret::factory()->for($owner)->revoked()->create(['label' => 'Revoked', 'created_at' => now()->subHours(4)]);
    $expired = Secret::factory()->for($owner)->expired()->create(['label' => 'Ran out', 'created_at' => now()->subHours(5)]);
    Secret::factory()->create(['label' => 'Someone else']);

    $this->actingAs($owner)->get(route('secrets.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('secrets/index', false)
        ->where('secrets.data', fn ($secrets): bool => collect($secrets)->pluck('id')->all() === [$opened->id, $soon->id, $later->id, $deleted->id, $expired->id])
        ->where('totals', ['total' => 5, 'active' => 2])
        ->where('statusCounts', ['all' => 5, 'active' => 2, 'soon' => 1, 'expired' => 3])
        ->where('secrets.data.1.title', 'Unlabelled secret')->where('secrets.data.1.expiring_soon', true)
        ->missing('secrets.data.0.payload')->missing('secrets.data.0.token'));
    $this->get(route('secrets.index', ['search' => 'GOOGLE']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('secrets.data', 1)->where('secrets.data.0.id', $later->id)->where('statusCounts.all', 1)->where('totals.total', 5));
    $this->get(route('secrets.index', ['search' => 'unlabelled', 'status' => 'soon']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('secrets.data', 1)->where('secrets.data.0.id', $soon->id));
    $this->get(route('secrets.index', ['status' => 'expired', 'page' => 3]))->assertRedirect(route('secrets.index', ['status' => 'expired', 'page' => 1]));
    $this->getJson(route('secrets.index', ['status' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page->has('transfers.data', 0));
});

it('stores the ciphertext with only a hash of the key verifier', function (): void {
    $owner = User::factory()->create();
    $input = newSecretInput(['max_views' => 5, 'expires_in_hours' => 168]);

    $response = $this->actingAs($owner)->postJson(route('secrets.store'), $input)->assertCreated();

    $secret = Secret::query()->sole();
    $response->assertExactJson([
        'url' => route('shared.secrets.show', $secret->token),
        'expires_at' => now()->addDays(7)->toIso8601String(),
        'max_views' => 5,
    ]);
    expect($secret->user_id)->toBe($owner->id)
        ->and($secret->label)->toBe('Token Google Ads (dev)')
        ->and($secret->payload)->toBe($input['payload'])
        ->and($secret->verifier_hash)->toBe(hash('sha256', $input['verifier']))
        ->and($secret->getAttributes())->not->toContain($input['verifier'])
        ->and($secret->view_count)->toBe(0)
        ->and(mb_strlen($secret->token))->toBe(32);
});

it('rejects malformed secrets', function (array $overrides, string $field): void {
    $this->actingAs(User::factory()->create())->postJson(route('secrets.store'), newSecretInput($overrides))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Secret::query()->count())->toBe(0);
})->with([
    'missing payload' => [['payload' => null], 'payload'],
    'plain text payload' => [['payload' => 'not base64, just the password in clear'], 'payload'],
    'oversized payload' => [['payload' => str_repeat('A', 60004)], 'payload'],
    'short verifier' => [['verifier' => str_repeat('V', 42)], 'verifier'],
    'raw key as verifier' => [['verifier' => '7KQM-X4TA-9RVB-2HNE-PW6C'], 'verifier'],
    'unsupported views' => [['max_views' => 3], 'max_views'],
    'unsupported expiry' => [['expires_in_hours' => 2], 'expires_in_hours'],
    'long label' => [['label' => str_repeat('a', 121)], 'label'],
]);

it('refuses the last view when another request spent it after the secret was loaded', function (): void {
    $verifier = str_repeat('k', 43);
    $secret = secretWithVerifier($verifier);
    // Another recipient takes the only view between route binding and the conditional increment.
    Secret::retrieved(fn (Secret $loaded) => Secret::query()->whereKey($loaded->id)->update(['view_count' => 1]));

    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertStatus(410)->assertJsonMissingPath('payload');

    expect($secret->refresh()->view_count)->toBe(1);
});

it('lets a guest open a once-only secret exactly once', function (): void {
    $verifier = str_repeat('k', 43);
    $secret = secretWithVerifier($verifier);
    $payload = $secret->payload;

    $this->get(route('shared.secrets.show', $secret->token))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page): Assert => $page->component('shared/secret', false)
            ->where('secret.available', true)->where('secret.views_left', 1)->where('secret.reason', null)->missing('secret.payload'));

    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertOk()
        ->assertExactJson(['payload' => $payload, 'views_left' => 0, 'opened_at' => now()->toIso8601String()]);

    expect($secret->refresh()->payload)->toBeNull()
        ->and($secret->view_count)->toBe(1)
        ->and($secret->last_opened_at?->toIso8601String())->toBe(now()->toIso8601String());
    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertStatus(410);
    $this->get(route('shared.secrets.show', $secret->token))->assertStatus(410)
        ->assertInertia(fn (Assert $page): Assert => $page->where('secret.available', false)->where('secret.reason', 'opened')
            ->where('secret.last_opened_at', now()->toIso8601String()));
});

it('counts down the views of a five-view secret and drops the ciphertext on the last one', function (): void {
    $verifier = str_repeat('k', 43);
    $secret = secretWithVerifier($verifier, ['max_views' => 5]);

    foreach ([4, 3, 2, 1, 0] as $viewsLeft) {
        $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertOk()->assertJsonPath('views_left', $viewsLeft);
        expect($secret->refresh()->payload === null)->toBe($viewsLeft === 0);
    }

    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertStatus(410);
});

it('keeps the view when the key is wrong and throttles the fifth miss for a minute', function (): void {
    $verifier = str_repeat('k', 43);
    $secret = secretWithVerifier($verifier);
    $wrong = ['verifier' => str_repeat('w', 43)];

    foreach (range(1, 4) as $attempt) {
        $this->postJson(route('shared.secrets.open', $secret->token), $wrong)->assertUnprocessable()
            ->assertJsonValidationErrors(['key' => 'That key doesn’t open this secret. Check it and try again.']);
    }

    $this->postJson(route('shared.secrets.open', $secret->token), $wrong)->assertTooManyRequests()
        ->assertJsonPath('message', 'Too many tries. Wait a minute, then try again.');
    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertTooManyRequests();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->postJson(route('shared.secrets.open', $secret->token), $wrong)->assertUnprocessable();

    expect($secret->refresh()->wrong_key_count)->toBe(6)
        ->and($secret->last_wrong_key_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($secret->view_count)->toBe(0)
        ->and($secret->payload)->not->toBeNull();

    $this->travel(61)->seconds();
    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertOk();
});

it('refuses the right key for expired and deleted secrets', function (string $state, string $reason): void {
    $verifier = str_repeat('k', 43);
    $secret = Secret::factory()->{$state}()->create(['verifier_hash' => hash('sha256', $verifier)]);

    $this->get(route('shared.secrets.show', $secret->token))->assertStatus(410)
        ->assertInertia(fn (Assert $page): Assert => $page->where('secret.available', false)->where('secret.reason', $reason));
    $this->postJson(route('shared.secrets.open', $secret->token), ['verifier' => $verifier])->assertStatus(410);
    expect($secret->refresh()->view_count)->toBe(0);
})->with([
    'expired' => ['expired', 'expired'],
    'deleted' => ['revoked', 'deleted'],
]);

it('renders an unknown secret link as unavailable', function (): void {
    $this->get(route('shared.secrets.show', 'missing-token'))->assertNotFound()
        ->assertInertia(fn (Assert $page): Assert => $page->component('shared/secret', false)->where('secret', null));
    $this->postJson(route('shared.secrets.open', 'missing-token'), ['verifier' => str_repeat('k', 43)])->assertNotFound();
});

it('shows the sender unless they hide their name on transfers', function (bool $showName): void {
    $sender = User::factory()->create(['name' => 'Filippo Fortino']);
    $sender->settings()->create(['show_name_on_transfers' => $showName]);
    $secret = Secret::factory()->for($sender)->create();

    $this->get(route('shared.secrets.show', $secret->token))->assertInertia(fn (Assert $page): Assert => $showName
        ? $page->where('secret.sender.name', 'Filippo Fortino')
        : $page->where('secret.sender', null));
})->with(['shown' => true, 'hidden' => false]);

it('shows and deletes a secret only for its owner', function (): void {
    $owner = User::factory()->create();
    $secret = Secret::factory()->for($owner)->create(['label' => null]);

    $this->actingAs(User::factory()->create())->get(route('secrets.show', $secret))->assertForbidden();
    $this->delete(route('secrets.destroy', $secret))->assertForbidden();

    $this->flushSession()->actingAs($owner)->get(route('secrets.show', $secret))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('secrets/show', false)->where('secret.title', 'Unlabelled secret')->where('secret.labelled', false)
        ->where('secret.available', true)->missing('secret.payload')->missing('secret.token'));
    $this->delete(route('secrets.destroy', $secret))->assertRedirect(route('secrets.index'))
        ->assertInertiaFlash('toast.title', 'Secret deleted');

    expect($secret->refresh()->revoked_at?->toIso8601String())->toBe(now()->toIso8601String())->and($secret->payload)->toBeNull();
    $this->get(route('shared.secrets.show', $secret->token))->assertStatus(410)
        ->assertInertia(fn (Assert $page): Assert => $page->where('secret.reason', 'deleted'));
});

it('keeps an opened secret marked as opened when its owner deletes it afterwards', function (): void {
    $owner = User::factory()->create();
    $secret = Secret::factory()->for($owner)->opened()->create();

    $this->actingAs($owner)->delete(route('secrets.destroy', $secret))->assertRedirect(route('secrets.index'));

    expect($secret->refresh()->revoked_at)->toBeNull()->and($secret->unavailableReason())->toBe('opened');
});

it('purges the ciphertext of expired secrets and of used ones that kept it', function (): void {
    $active = Secret::factory()->create();
    $partlyOpened = Secret::factory()->views(5)->create(['view_count' => 2]);
    $expired = Secret::factory()->expired()->create();
    // The view was spent, but the process died before the ciphertext was cleared.
    $used = Secret::factory()->create(['view_count' => 1, 'last_opened_at' => now()]);

    $this->artisan('filemax:purge-secrets')->expectsOutputToContain('Purged 2 expired or opened secrets.')->assertSuccessful();

    expect($active->refresh()->payload)->not->toBeNull()
        ->and($partlyOpened->refresh()->payload)->not->toBeNull()
        ->and($expired->refresh()->payload)->toBeNull()
        ->and($used->refresh()->payload)->toBeNull();
});

it('purges secrets once a day, in the same run as the transfer cleanup', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();
    $expressions = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'filemax:purge-secrets') || str_contains($event->command ?? '', 'filemax:cleanup'))
        ->map(fn (Event $event): string => $event->expression);

    expect($expressions->values()->all())->toBe(['0 0 * * *', '0 0 * * *']);
});
