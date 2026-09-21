<?php

declare(strict_types=1);

use App\Jobs\PrepareTransferArchive;
use App\Models\Team;
use App\Models\Transfer;
use App\Models\TransferFile;
use App\Models\User;
use App\Services\TransferStorage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('local');
    config(['filemax.disk' => 'local']);
    $this->withoutVite();
});

it('records the first public page opening without counting a download or exposing storage paths', function (): void {
    $transfer = Transfer::factory()->create(['title' => null]);
    $file = TransferFile::factory()->for($transfer)->create();

    $this->get(route('shared.show', $transfer->token))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/show', false)
        ->where('transfer.title', $file->original_name)
        ->where('transfer.files.0.name', $file->original_name)
        ->missing('transfer.files.0.path')
        ->missing('transfer.download_count'));
    $firstOpened = $transfer->refresh()->first_opened_at;
    $this->travel(5)->minutes();
    $this->get(route('shared.show', $transfer->token))->assertOk();

    expect($transfer->refresh()->download_count)->toBe(0)
        ->and($file->refresh()->download_count)->toBe(0)
        ->and($transfer->first_opened_at->equalTo($firstOpened))->toBeTrue();
});

it('counts every authorized individual action exactly once and never counts byte delivery', function (): void {
    $transfer = Transfer::factory()->create();
    $file = TransferFile::factory()->for($transfer)->create(['original_name' => '../../unsafe.txt']);
    Storage::disk('local')->put($file->path, 'test');

    $url = $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk()->json('url');
    $this->get($url)->assertOk()->assertDownload('unsafe.txt')->assertStreamedContent('test');
    $this->get($url)->assertOk();
    $this->head($url)->assertOk();
    $this->actingAs($transfer->user)->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk();

    expect($transfer->refresh()->download_count)->toBe(2)
        ->and($transfer->last_downloaded_at)->not->toBeNull()
        ->and($file->refresh()->download_count)->toBe(2);
});

it('redirects restricted guests back through login and keeps denied props private', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams', 'message' => 'secret message']);
    TransferFile::factory()->for($transfer)->create(['original_name' => 'secret.pdf']);
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $url = route('shared.show', $transfer->token);

    $this->get($url)->assertRedirect(route('login'))->assertSessionHas('url.intended', $url);
    $user = User::factory()->create();
    $ownTeam = Team::factory()->create();
    $user->teams()->attach($ownTeam);
    $this->actingAs($user)->get($url)->assertForbidden()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/denied', false)
        ->where('sender.email', $transfer->user->email)
        ->where('own_teams.0.name', $ownTeam->name)
        ->missing('transfer')
        ->missing('files')
        ->missing('message'));
    $this->postJson(route('shared.download', $transfer->token))->assertForbidden();
    expect($transfer->refresh()->first_opened_at)->toBeNull()->and($transfer->download_count)->toBe(0);
});

it('requires verified eligibility and current membership while allowing any selected team', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $file = TransferFile::factory()->for($transfer)->create();
    $teams = Team::factory()->count(2)->create();
    $transfer->teams()->attach($teams->modelKeys());
    $recipient = User::factory()->create();
    $recipient->teams()->attach($teams->modelKeys());
    $route = route('shared.files.download', [$transfer->token, $file]);

    $this->actingAs($recipient)->postJson($route)->assertOk();
    expect($transfer->refresh()->download_count)->toBe(1);
    $recipient->teams()->detach($teams[0]);
    $this->postJson($route)->assertOk();
    $recipient->teams()->detach();
    $this->postJson($route)->assertForbidden();
    $recipient->teams()->attach($teams[0]);
    $recipient->forceFill(['email_verified_at' => null])->save();
    $this->postJson($route)->assertForbidden();
    $recipient->forceFill(['email_verified_at' => now(), 'email' => 'person@example.com'])->save();
    $this->postJson($route)->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(2);
});

it('blocks expiry, deletion, missing tokens and foreign file ids without counting them', function (): void {
    $transfer = Transfer::factory()->create();
    $file = TransferFile::factory()->for($transfer)->create();
    $foreign = TransferFile::factory()->create();
    Storage::disk('local')->put($file->path, 'test');

    $this->postJson(route('shared.files.download', [$transfer->token, $foreign]))->assertNotFound();
    $this->get(route('shared.show', 'missing'))->assertNotFound()->assertInertia(fn (Assert $page): Assert => $page
        ->component('shared/unavailable', false)
        ->where('reason', 'unavailable'));
    $this->get(route('shared.show', $transfer->id))->assertNotFound();
    $transfer->update(['expires_at' => now()]);
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertGone();
    $this->get(route('shared.show', $transfer->token))->assertGone();
    Storage::disk('local')->assertExists($file->path);
    $transfer->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
    $this->postJson(route('shared.files.download', [$transfer->token, $file]))->assertGone();
    expect($transfer->refresh()->download_count)->toBe(0);
});

it('caps signed file links at transfer expiry and rechecks membership on local retrieval', function (): void {
    $transfer = Transfer::factory()->create(['visibility' => 'teams', 'expires_at' => now()->addSeconds(30)]);
    $file = TransferFile::factory()->for($transfer)->create();
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $user = User::factory()->create();
    $user->teams()->attach($team);
    $url = $this->actingAs($user)->postJson(route('shared.files.download', [$transfer->token, $file]))->assertOk()->json('url');
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe($transfer->expires_at->timestamp);
    $user->teams()->detach();
    $this->get($url)->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(1);
});

it('builds archives with safe unique names and counts bundles independently of file clicks', function (): void {
    $transfer = Transfer::factory()->create();
    foreach (['../report.txt', 'report.txt', 'report (2).txt', 'folder\\REPORT.txt'] as $position => $name) {
        $file = TransferFile::factory()->for($transfer)->create(['original_name' => $name, 'position' => $position]);
        Storage::disk('local')->put($file->path, 'test');
    }

    $response = $this->postJson(route('shared.download', $transfer->token))->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('progress', 100);
    $this->getJson(route('shared.archive', $transfer->token))->assertOk();
    $this->get($response->json('url'))->assertOk()->assertDownload();
    $transfer->refresh();
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('local')->path($transfer->archive_path)))->toBeTrue();
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
        expect($zip->getFromIndex($index))->toBe('test');
    }

    $zip->close();
    expect($names)->toBe(['report.txt', 'report (2).txt', 'report (2) (2).txt', 'REPORT (3).txt']);
    $this->postJson(route('shared.download', $transfer->token))->assertOk();
    expect($transfer->refresh()->download_count)->toBe(2)->and($transfer->files()->sum('download_count'))->toBe(0);
});

it('rechecks access while archives prepare and when they are retrieved', function (): void {
    Queue::fake([PrepareTransferArchive::class]);
    $transfer = Transfer::factory()->create(['visibility' => 'teams']);
    $file = TransferFile::factory()->for($transfer)->create();
    Storage::disk('local')->put($file->path, 'test');
    $team = Team::factory()->create();
    $transfer->teams()->attach($team);
    $user = User::factory()->create();
    $user->teams()->attach($team);
    $this->actingAs($user)->postJson(route('shared.download', $transfer->token))->assertOk()->assertJsonPath('status', 'pending');
    Queue::assertPushed(PrepareTransferArchive::class, 1);
    $user->teams()->detach();
    new PrepareTransferArchive($transfer->id)->handle(resolve(TransferStorage::class));
    $this->getJson(route('shared.archive', $transfer->token))->assertForbidden();
    $this->get(route('shared.archive.download', $transfer->token))->assertForbidden();
    expect($transfer->refresh()->download_count)->toBe(1);
});

it('marks failed archives for a recoverable explicit retry', function (): void {
    $transfer = Transfer::factory()->create(['archive_status' => 'processing']);
    $file = TransferFile::factory()->for($transfer)->create();
    $job = new PrepareTransferArchive($transfer->id);
    $job->failed(new RuntimeException('Worker failed.'));

    expect($transfer->refresh()->archive_status)->toBe('failed');
    Storage::disk('local')->put($file->path, 'test');
    $this->postJson(route('shared.download', $transfer->token))->assertOk()->assertJsonPath('status', 'ready');
});

it('counts a download once when an archive worker updates SQLite during authorization', function (bool $individual): void {
    $database = tempnam(sys_get_temp_dir(), 'filemax-download-');
    $originalConnection = DB::getDefaultConnection();
    $configuration = array_replace(config('database.connections.sqlite'), [
        'database' => $database,
        'journal_mode' => 'WAL',
        'busy_timeout' => 50,
    ]);
    config(['database.connections.download_test' => $configuration, 'database.connections.download_writer' => $configuration]);
    DB::setDefaultConnection('download_test');

    try {
        Artisan::call('migrate', ['--database' => 'download_test', '--force' => true]);
        $transfer = Transfer::factory()->create(['archive_status' => 'processing']);
        $file = TransferFile::factory()->for($transfer)->create();
        $authorizationReads = 0;

        DB::listen(function (QueryExecuted $query) use ($transfer, &$authorizationReads): void {
            if ($query->connectionName === 'download_test' && str_starts_with($query->sql, 'select * from "transfers" where "token"')) {
                $authorizationReads++;
                if ($authorizationReads === 1) {
                    DB::connection('download_writer')->table('transfers')->where('id', $transfer->id)->update(['archive_progress' => 40]);
                }
            }
        });

        $url = $individual
            ? route('shared.files.download', [$transfer->token, $file])
            : route('shared.download', $transfer->token);
        $this->postJson($url)->assertOk();

        expect($authorizationReads)->toBe(2)
            ->and($transfer->refresh()->download_count)->toBe(1)
            ->and($transfer->archive_progress)->toBe(40)
            ->and($file->refresh()->download_count)->toBe($individual ? 1 : 0);
    } finally {
        DB::purge('download_test');
        DB::purge('download_writer');
        DB::setDefaultConnection($originalConnection);
        File::delete([$database, $database.'-wal', $database.'-shm']);
    }
})->with(['individual' => true, 'all files' => false]);
