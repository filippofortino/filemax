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
    $this->actingAs(User::factory()->create())->get(route('transfers.index'))->assertInertia(fn (Assert $page): Assert => $page->has('transfers.data', 0)->where('totals.total', 0));
});

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
