<?php

declare(strict_types=1);

use App\Jobs\PurgeTransfer;
use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->withoutVite();
    config(['inertia.testing.ensure_pages_exist' => false]);
});

it('lists only owned transfers with historical team filters and expired totals', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $owned = Transfer::factory()->for($owner)->create(['visibility' => 'teams']);
    $owned->teams()->attach($team);
    TransferFile::factory()->for($owned)->create();
    Transfer::factory()->for($owner)->expired()->create();
    Transfer::factory()->create();
    $this->actingAs($owner)->get(route('transfers.index'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('transfers/index', false)->has('transfers.data', 2)->where('totals.active', 1)->where('totals.expired', 1)->where('teams.0.id', $team->id));
    $this->get(route('transfers.index', ['filter' => $team->id]))->assertInertia(fn (Assert $page): Assert => $page->has('transfers.data', 1)->where('transfers.data.0.id', $owned->id));
    $this->get(route('transfers.index', ['filter' => 'public']))->assertInertia(fn (Assert $page): Assert => $page->has('transfers.data', 1));
});

it('renders an empty owner history', function (): void {
    $this->actingAs(User::factory()->create())->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 0)->where('totals.total', 0)
        ->where('filter', 'all')->where('status', 'all')->where('search', '')
        ->where('statusCounts', ['all' => 0, 'active' => 0, 'soon' => 0, 'expired' => 0]));
});

it('filters availability and counts the inclusive next day without crossing ownership boundaries', function (string $status, array $expectedTitles): void {
    $owner = User::factory()->create();
    $states = [
        ['title' => 'soon', 'expires_at' => now()->addSecond()],
        ['title' => 'boundary', 'expires_at' => now()->addDay()],
        ['title' => 'later', 'expires_at' => now()->addDay()->addSecond()],
        ['title' => 'expired', 'expires_at' => now()],
        ['title' => 'revoked', 'revoked_at' => now()],
        ['title' => 'purged', 'purged_at' => now()],
        ['title' => 'no expiry', 'expires_at' => null],
    ];
    foreach ($states as $state) {
        Transfer::factory()->for($owner)->create($state);
        Transfer::factory()->create($state);
    }

    Transfer::factory()->for($owner)->uploading()->create();

    $this->actingAs($owner)->get(route('transfers.index', ['status' => $status]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', count($expectedTitles))
        ->where('transfers.data', fn ($transfers): bool => collect($transfers)->pluck('title')->sort()->values()->all() === collect($expectedTitles)->sort()->values()->all())
        ->where('transfers.data', fn ($transfers): bool => collect($transfers)->every(fn (array $transfer): bool => $transfer['expiring_soon'] === in_array($transfer['title'], ['soon', 'boundary'], true)))
        ->where('statusCounts', ['all' => 7, 'active' => 3, 'soon' => 2, 'expired' => 4])
        ->where('totals.total', 7)->where('totals.active', 3)->where('totals.expired', 4)
        ->where('status', $status));
})->with([
    'all' => ['all', ['soon', 'boundary', 'later', 'expired', 'revoked', 'purged', 'no expiry']],
    'active' => ['active', ['soon', 'boundary', 'later']],
    'soon' => ['soon', ['soon', 'boundary']],
    'expired' => ['expired', ['expired', 'revoked', 'purged', 'no expiry']],
]);

it('counts audience and search matches before the selected status while preserving global totals', function (): void {
    $owner = User::factory()->create();
    $team = Team::factory()->create();
    $match = Transfer::factory()->for($owner)->create(['title' => 'Campaign active', 'visibility' => 'teams', 'expires_at' => now()->addHour()]);
    $match->teams()->attach($team);
    $expired = Transfer::factory()->for($owner)->expired()->create(['title' => 'Campaign expired', 'visibility' => 'teams']);
    $expired->teams()->attach($team);
    Transfer::factory()->for($owner)->create(['title' => 'Campaign public']);
    $other = Transfer::factory()->for($owner)->create(['title' => 'Different title', 'visibility' => 'teams']);
    $other->teams()->attach($team);
    $foreign = Transfer::factory()->expired()->create(['title' => 'Campaign foreign', 'visibility' => 'teams']);
    $foreign->teams()->attach($team);

    $this->actingAs($owner)->get(route('transfers.index', ['filter' => $team->id, 'search' => 'campaign', 'status' => 'active']))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.id', $match->id)
        ->where('statusCounts', ['all' => 2, 'active' => 1, 'soon' => 1, 'expired' => 1])
        ->where('totals.total', 4)->where('totals.active', 3)->where('totals.expired', 1)
        ->where('teams.0.id', $team->id));
    $this->get(route('transfers.index', ['filter' => 'public', 'search' => 'campaign']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.title', 'Campaign public')
        ->where('statusCounts.all', 1)->where('totals.total', 4));
});

it('searches displayed titles case insensitively with only the first filename as a fallback', function (): void {
    $owner = User::factory()->create();
    $title = Transfer::factory()->for($owner)->create(['title' => 'Quarterly title']);
    $fallback = Transfer::factory()->for($owner)->create(['title' => null]);
    TransferFile::factory()->for($fallback)->create(['original_name' => 'Quarterly first.pdf', 'position' => 3]);
    TransferFile::factory()->for($fallback)->create(['original_name' => 'Other second.pdf', 'position' => 5]);
    $hidden = Transfer::factory()->for($owner)->create(['title' => 'Unrelated title']);
    TransferFile::factory()->for($hidden)->create(['original_name' => 'Quarterly hidden.pdf']);
    $laterFile = Transfer::factory()->for($owner)->create(['title' => null]);
    TransferFile::factory()->for($laterFile)->create(['original_name' => 'Different first.pdf', 'position' => 0]);
    TransferFile::factory()->for($laterFile)->create(['original_name' => 'Quarterly second.pdf', 'position' => 1]);
    Transfer::factory()->create(['title' => 'Quarterly foreign']);
    Transfer::factory()->for($owner)->uploading()->create(['title' => 'Quarterly draft']);
    $untitled = Transfer::factory()->for($owner)->create(['title' => null]);
    $zero = Transfer::factory()->for($owner)->create(['title' => '0']);

    $this->actingAs($owner)->get(route('transfers.index', ['search' => '  QUARTERLY  ']))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 2)->where('search', 'QUARTERLY')
        ->where('transfers.data', fn ($transfers): bool => collect($transfers)->pluck('id')->sort()->values()->all() === collect([$title->id, $fallback->id])->sort()->values()->all()));
    $this->get(route('transfers.index', ['search' => 'untitled transfer']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.id', $untitled->id));
    $this->get(route('transfers.index', ['search' => '0']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.id', $zero->id));
    $this->get(route('transfers.index', ['search' => 'missing title']))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 0)->where('statusCounts.all', 0)->where('totals.total', 6));
});

it('treats search pattern characters literally', function (string $search): void {
    $owner = User::factory()->create();
    $match = Transfer::factory()->for($owner)->create(['title' => 'Budget 100%_\\! confirmed']);
    Transfer::factory()->for($owner)->create(['title' => 'Budget 100xx confirmed']);

    $this->actingAs($owner)->get(route('transfers.index', ['search' => $search]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.id', $match->id));
})->with(['percent' => ['%'], 'underscore' => ['_'], 'backslash' => ['\\'], 'escape' => ['!'], 'combined' => ['%_\\!']]);

it('matches accented displayed titles and fallback filenames case insensitively', function (string $search): void {
    $owner = User::factory()->create();
    Transfer::factory()->for($owner)->create(['title' => 'ÉTÉ photos']);
    $fallback = Transfer::factory()->for($owner)->create(['title' => null]);
    TransferFile::factory()->for($fallback)->create(['original_name' => 'été affiche.pdf']);
    Transfer::factory()->for($owner)->create(['title' => 'Other photos']);

    $this->actingAs($owner)->get(route('transfers.index', ['search' => $search]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 2)->where('statusCounts.all', 2));
})->with(['uppercase' => ['ÉTÉ'], 'lowercase' => ['été']]);

it('trims search before validating its length', function (): void {
    $title = str_repeat('a', 255);
    $transfer = Transfer::factory()->create(['title' => $title]);

    $this->actingAs($transfer->user)->get(route('transfers.index', ['search' => "\u{00A0}".$title."\u{00A0}"]))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('search', $title));
});

it('searches the full history and preserves filters across stable eight-item pages', function (): void {
    $owner = User::factory()->create();
    $matches = Transfer::factory()->for($owner)->count(9)->create(['title' => 'Campaign', 'created_at' => now()->subDay()]);
    Transfer::factory()->for($owner)->count(9)->create(['title' => 'Unrelated recent transfer']);
    $filters = ['filter' => 'public', 'status' => 'active', 'search' => 'Campaign'];
    $expectedIds = $matches->sortByDesc('id')->pluck('id')->values();

    $this->actingAs($owner)->get(route('transfers.index', $filters))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 8)->where('transfers.meta.total', 9)->where('transfers.meta.last_page', 2)->where('transfers.meta.per_page', 8)
        ->where('transfers.data', fn ($transfers): bool => collect($transfers)->pluck('id')->all() === $expectedIds->take(8)->all())
        ->where('transfers.links.next', function (string $url) use ($filters): bool {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            return $query === [...$filters, 'page' => '2'];
        }));
    $this->get(route('transfers.index', [...$filters, 'page' => 2]))->assertInertia(fn (Assert $page): Assert => $page
        ->has('transfers.data', 1)->where('transfers.data.0.id', $expectedIds->last()));
});

it('rejects malformed transfer filters', function (array $query, string $field): void {
    $this->actingAs(User::factory()->create())->getJson(route('transfers.index', $query))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'invalid audience' => [['filter' => 'unknown'], 'filter'],
    'array audience' => [['filter' => ['public']], 'filter'],
    'invalid status' => [['status' => 'unknown'], 'status'],
    'array status' => [['status' => ['active']], 'status'],
    'array search' => [['search' => ['title']], 'search'],
    'long search' => [['search' => str_repeat('a', 256)], 'search'],
    'array page' => [['page' => ['1']], 'page'],
    'zero page' => [['page' => 0], 'page'],
    'invalid page' => [['page' => 'invalid'], 'page'],
]);

it('separates opened status from download counts and does not expose storage keys', function (): void {
    $transfer = Transfer::factory()->create(['first_opened_at' => now(), 'download_count' => 0]);
    TransferFile::factory()->for($transfer)->create();
    $this->actingAs($transfer->user)->get(route('transfers.show', $transfer))->assertOk()->assertInertia(fn (Assert $page): Assert => $page->has('transfer.files', 1)->has('transfer.files.0.id')->where('transfer.download_count', 0)->where('transfer.first_opened_at', now()->toIso8601String())->missing('transfer.files.0.path'));
});

it('never grants ownership via membership or admin status', function (): void {
    $transfer = Transfer::factory()->create();
    $this->actingAs(User::factory()->admin()->create())->get(route('transfers.show', $transfer))->assertForbidden();
    $this->patchJson(route('transfers.update', $transfer), ['team_ids' => []])->assertForbidden();
    $this->deleteJson(route('transfers.destroy', $transfer))->assertForbidden();
});

it('changes teams atomically without changing files token or expiry', function (): void {
    $transfer = Transfer::factory()->expired()->create(['visibility' => 'teams']);
    $teams = Team::factory()->count(2)->create();
    $transfer->user->teams()->attach($teams);
    $transfer->teams()->attach($teams[0]);
    $token = $transfer->token;
    $expiry = $transfer->expires_at->toIso8601String();
    $this->actingAs($transfer->user)->patchJson(route('transfers.update', $transfer), ['team_ids' => []])->assertUnprocessable();
    expect($transfer->teams()->pluck('teams.id')->all())->toBe([$teams[0]->id]);
    $this->patchJson(route('transfers.update', $transfer), ['team_ids' => [$teams[1]->id]])->assertOk();
    $transfer->refresh();
    expect($transfer->teams()->pluck('teams.id')->all())->toBe([$teams[1]->id]);
    expect($transfer->token)->toBe($token);
    expect($transfer->expires_at->toIso8601String())->toBe($expiry);
    expect($transfer->isAvailable())->toBeFalse();
});

it('revokes immediately and queues idempotent physical deletion', function (): void {
    Queue::fake();
    $transfer = Transfer::factory()->create();
    $this->actingAs($transfer->user)->deleteJson(route('transfers.destroy', $transfer))->assertOk();
    $revokedAt = $transfer->refresh()->revoked_at;
    expect($transfer->isAvailable())->toBeFalse();
    $this->deleteJson(route('transfers.destroy', $transfer))->assertOk();
    expect($transfer->refresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
    Queue::assertPushed(PurgeTransfer::class);
});

it('confirms sharing changes and deletions with a toast', function (): void {
    Queue::fake();
    $transfer = Transfer::factory()->create(['visibility' => 'teams', 'title' => 'Brief Q4']);
    $team = Team::factory()->create();
    $transfer->user->teams()->attach($team);
    $this->actingAs($transfer->user)->from(route('transfers.show', $transfer))->patch(route('transfers.update', $transfer), ['team_ids' => [$team->id]])
        ->assertRedirect(route('transfers.show', $transfer))->assertInertiaFlash('toast.title', 'Sharing updated');
    $this->delete(route('transfers.destroy', $transfer))->assertRedirect(route('transfers.index'))
        ->assertInertiaFlash('toast.title', 'Transfer deleted')->assertInertiaFlash('toast.description', 'The link to “Brief Q4” no longer works.');
});

it('revokes and changes sharing immediately while an archive holds the byte lock', function (): void {
    Queue::fake();
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $teams = Team::factory()->count(2)->create();
    $transfer->user->teams()->attach($teams);
    $transfer->teams()->attach($teams[0]);
    $lock = Cache::lock('transfer-bytes:'.$transfer->id, 3700);
    $lock->get();
    try {
        $this->actingAs($transfer->user)->patchJson(route('transfers.update', $transfer), ['team_ids' => [$teams[1]->id]])->assertOk();
        expect($transfer->teams()->pluck('teams.id')->all())->toBe([$teams[1]->id]);
        $this->deleteJson(route('transfers.destroy', $transfer))->assertOk();
        expect($transfer->refresh()->isAvailable())->toBeFalse();
    } finally {
        $lock->release();
    }
});

it('preserves a title consisting of zero', function (): void {
    $transfer = Transfer::factory()->create(['title' => '0']);
    expect($transfer->displayTitle())->toBe('0');
});
