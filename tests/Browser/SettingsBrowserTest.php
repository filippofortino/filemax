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
        ->assertSee('Your profile, privacy, notifications, password, and the devices you sign in with.')
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
        ->assertSeeIn('[data-slot="toast"]', 'Profile saved')
        ->click('[aria-label$="account menu"]')
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
    $page->press('Save profile')->assertSeeIn('[data-slot="toast"]', 'Profile saved')
        ->assertMissing('#profile-form img')->assertMissing('header img[src]')
        ->assertNoJavascriptErrors();
    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($path);
});

it('hides the sender name on transfers with the privacy switch', function (): void {
    $user = User::factory()->create(['name' => 'Filippo Fortino']);
    $transfer = Transfer::factory()->for($user, 'user')->create(['title' => 'Spot autunno']);
    $this->actingAs($user);

    visit('/account/settings')
        ->assertSee('Sent by Filippo Fortino')
        ->assertAttribute('[aria-labelledby="show-name-label"]', 'aria-checked', 'true')
        ->click('[aria-labelledby="show-name-label"]')
        ->assertSee('No sender shown, only the files')
        ->assertSeeIn('[data-slot="toast"]', 'Privacy saved')
        ->assertSee('Recipients no longer see your name or photo.')
        ->assertAttribute('[aria-labelledby="show-name-label"]', 'aria-checked', 'false')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'settings-privacy-hidden');

    expect($user->settings()->sole()->show_name_on_transfers)->toBeFalse();

    visit(route('shared.show', $transfer->token))->resize(1280, 940)
        ->assertSee('Spot autunno')
        ->assertDontSee('Filippo Fortino')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'filemax-recipient-sender-hidden');
});

it('turns on the first-download email and keeps the expiry reminder on', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    visit('/account/settings')
        ->assertSee("Emails about your transfers, sent to {$user->email}.")
        ->assertAttribute('[aria-labelledby="notify-expiry-label"]', 'aria-checked', 'true')
        ->assertAttribute('[aria-labelledby="notify-download-label"]', 'aria-checked', 'false')
        ->click('[aria-labelledby="notify-download-label"]')
        ->assertSeeIn('[data-slot="toast"]', 'Notifications saved')
        ->assertSee('We’ll email you the first time someone downloads a transfer.')
        ->assertAttribute('[aria-labelledby="notify-download-label"]', 'aria-checked', 'true')
        ->assertAttribute('[aria-labelledby="notify-expiry-label"]', 'aria-checked', 'true')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'settings-notifications');

    expect($user->settings()->sole()->only(['notify_transfer_expiring', 'notify_transfer_downloaded']))
        ->toBe(['notify_transfer_expiring' => true, 'notify_transfer_downloaded' => true]);
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
        ->assertSeeIn('[data-slot="toast"]', 'Password updated')
        ->assertSee('You’re signed out everywhere else.')
        ->assertSee('Profile')
        ->assertNoJavascriptErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
    expect($page->script('() => [...document.querySelectorAll("#password-form input")].every(input => input.value === "")'))->toBeTrue();
    $page->click('New transfer')->assertSee('Transfer details');
});

it('shows one connection toast when saves cannot reach the server and fans the stack out on hover', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit('/account/settings')->resize(1280, 940);
    $page->script(<<<'JS'
() => {
    window.realSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function () {
        this.dispatchEvent(new ProgressEvent('error'));
    };
}
JS);

    $page->fill('#password-current', 'password')
        ->fill('#password-new', 'new-password')
        ->fill('#password-repeat', 'new-password')
        ->press('Update password')
        ->assertSeeIn('[data-slot="toast"]', 'Connection lost')
        ->assertSee('Check your connection and try again.')
        ->press('Save profile')
        ->wait(0.3)
        ->assertCount('[data-slot="toast"]', 1)
        ->assertNoJavascriptErrors();

    $page->script('() => { XMLHttpRequest.prototype.send = window.realSend; }');
    $page->press('Save profile')
        ->assertSeeIn('[data-slot="toast"]', 'Profile saved')
        ->assertCount('[data-slot="toast"]', 2)
        ->assertScript(<<<'JS'
() => {
    const toast = [...document.querySelectorAll('[data-slot="toast"]')]
        .find(toast => toast.textContent.includes('Connection lost'));
    return toast.querySelector('[data-slot="toast-content"]').offsetHeight > toast.offsetHeight;
}
JS)
        ->press('[data-slot="toast"]:has-text("Profile saved") [aria-label="Dismiss"]')
        ->assertCount('[data-slot="toast"]', 1)
        ->assertNoJavascriptErrors();

    $page->press('Update password')
        ->assertSeeIn('[data-slot="toast"]', 'Password updated')
        ->assertCount('[data-slot="toast"]', 2)
        ->assertMissing('[data-slot="toast-viewport"][data-expanded]')
        ->wait(0.3)
        ->screenshot(fullPage: false, filename: 'toast-stacked')
        ->hover('[data-slot="toast"]:has-text("Password updated")')
        ->assertPresent('[data-slot="toast-viewport"][data-expanded]')
        ->wait(0.3)
        ->screenshot(fullPage: false, filename: 'toast-expanded')
        ->assertNoJavascriptErrors();
    $page->resize(390, 844)->wait(0.3)->screenshot(fullPage: false, filename: 'toast-phone');

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

it('tabs from the header straight to Upload photo, which announces the photo requirements', function (): void {
    $this->actingAs(User::factory()->create());

    visit('/account/settings')
        ->keys('#profile-form button:has-text("Upload photo")', 'Shift+Tab')
        ->assertScript('Boolean(document.activeElement.closest("header"))')
        ->keys(':focus', 'Tab')
        ->assertScript('document.activeElement.innerText', 'Upload photo')
        ->assertScript('document.getElementById(document.activeElement.getAttribute("aria-describedby"))?.innerText.startsWith("JPG or PNG")')
        ->assertNoJavascriptErrors();
});
