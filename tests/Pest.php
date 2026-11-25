<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

function something(): void
{
    // ..
}

/** Adds the user to an organization (a new one unless given) and makes it the one they are working in. */
function joinOrganization(User $user, string $role = 'member', ?Organization $organization = null): Organization
{
    $organization ??= Organization::factory()->create();
    $organization->users()->attach($user, ['role' => $role]);
    $user->forceFill(['current_organization_id' => $organization->id])->save();

    return $organization;
}

/** Defines window.dropFolder([name, [children…]]), dropping a folder the way the browser hands one over: as entries to walk. */
function folderDropScript(): string
{
    return <<<'JS'
() => {
    const file = (name, text) => {
        const value = new File([text], name);
        return { isFile: true, isDirectory: false, name, file: (resolve) => resolve(value) };
    };
    const folder = (name, children) => ({
        isFile: false,
        isDirectory: true,
        name,
        createReader: () => {
            let read = false;
            return { readEntries: (resolve) => { resolve(read ? [] : children); read = true; } };
        },
    });
    window.dropFolder = (tree) => {
        const build = ([name, content]) => Array.isArray(content) ? folder(name, content.map(build)) : file(name, content);
        const root = build(tree);
        const event = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'dataTransfer', { value: { types: ['Files'], files: [], items: [{ webkitGetAsEntry: () => root }] } });
        document.querySelector('main').dispatchEvent(event);
    };
}
JS;
}
