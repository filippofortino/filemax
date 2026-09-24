<?php

declare(strict_types=1);

use App\Models\Transfer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

it('saves the profile name and renders settings on desktop and phone', function (): void {
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $this->actingAs($user);

    $page = visit('/account/settings')->resize(1280, 1512)
        ->assertSee('Your profile, your password, and the devices you sign in with.')
        ->assertSee('Upload photo')
        ->assertSee('Update password')
        ->assertSee('Add passkey')
        ->assertNoJavascriptErrors();

    expect($page->script('() => document.querySelector("#account-email").readOnly'))->toBeTrue();
    $page->script('() => document.fonts.ready');
    $page->screenshot(filename: 'settings-desktop');
    $page->resize(390, 844)->screenshot(filename: 'settings-phone');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->fill('#full-name', 'Alice Updated')->press('Save profile')
        ->assertSee('Profile saved.')
        ->click('[aria-label="Account menu"]')
        ->assertSee('Alice Updated')
        ->assertNoJavascriptErrors();

    expect($user->refresh()->name)->toBe('Alice Updated');
});

it('previews saves and removes a photo in settings and on sent transfers', function (): void {
    $disk = Storage::fake('local');
    $disk->buildTemporaryUrlsUsing(fn (string $path, DateTimeInterface $expiration): string => URL::temporarySignedRoute('storage.local', $expiration, ['path' => $path], absolute: false));
    Storage::fake('transfers');
    config(['filesystems.default' => 'local', 'filemax.disk' => 'transfers']);
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $transfer = Transfer::factory()->for($user, 'user')->create();
    $photo = UploadedFile::fake()->image('avatar.png', 400, 300);
    $this->actingAs($user);

    $page = visit('/account/settings')
        ->attach('#avatar', $photo->getPathname())
        ->assertVisible('#profile-form img');

    expect($user->refresh()->avatar_path)->toBeNull();
    expect($page->attribute('#profile-form img', 'src'))->toStartWith('blob:');

    // ponytail: Pest's HTTP server cannot parse multipart yet; submit in-browser when supported.
    $this->from(route('account.settings'))->post(route('user-profile-information.update'), [
        '_method' => 'PUT',
        'name' => $user->name,
        'avatar' => $photo,
    ])->assertRedirect(route('account.settings'))->assertSessionHasNoErrors();
    $path = $user->refresh()->avatar_path;
    $native = $this->get($user->avatarUrl())->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Content-Length', (string) $disk->size($path));
    expect($native->streamedContent())->toBe($disk->get($path));
    // ponytail: Pest's mb_trim truncates streamed binary; use native streaming when its server preserves bytes.
    $disk->serveUsing(fn (Request $request, string $path, array $headers): Response => response($disk->get($path), headers: [...$headers, 'Content-Type' => 'image/webp']));

    $page = visit('/account/settings')->assertSee('Settings');
    $page->assertVisible('header img[src]')->assertVisible('#profile-form img')->assertNoJavascriptErrors();
    $path = $user->refresh()->avatar_path;
    expect($path)->toBeString();
    Storage::disk('local')->assertExists($path);
    Storage::disk('transfers')->assertDirectoryEmpty('/');
    expect($page->script('() => Promise.all([...document.querySelectorAll("header img, #profile-form img")].map(image => image.decode())).then(() => true)'))->toBeTrue();

    $recipient = visit(route('shared.show', $transfer->token))->assertVisible('main img[src]');
    expect($recipient->script('() => document.querySelector("main img").decode().then(() => true)'))->toBeTrue();
    $recipient->script('() => document.querySelector("main img").dispatchEvent(new Event("error"))');
    $recipient->assertMissing('main img[src]')->assertSee('FF');

    $page->press('#profile-form button:has-text("Remove")')->assertMissing('#profile-form img');
    expect($user->refresh()->avatar_path)->toBe($path);
    $page->press('Save profile')->assertSee('Profile saved.')
        ->assertMissing('#profile-form img')->assertMissing('header img[src]')
        ->assertNoJavascriptErrors();
    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('shows password errors separately and keeps the current session after updating', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/account/settings')
        ->fill('#password-current', 'incorrect-password')
        ->fill('#password-new', 'new-password')
        ->fill('#password-repeat', 'new-password')
        ->press('Update password')
        ->assertSee('The password is incorrect.');

    expect($page->script('() => document.querySelector("#profile-form [role=alert]") === null'))->toBeTrue();
    $page->fill('#password-current', 'password')->press('Update password')
        ->assertSee('Password updated.')
        ->assertSee('Profile')
        ->assertNoJavascriptErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    expect($page->script('() => [...document.querySelectorAll("#password-form input")].every(input => input.value === "")'))->toBeTrue();
    $page->click('New transfer')->assertSee('Transfer details');
});
