<?php

declare(strict_types=1);

use App\Models\User;

it('confirms identity with keyboard before security settings and keeps the passkey name when adding one', function (): void {
    $user = User::factory()->create();

    $page = visit('/login')->resize(1280, 940)
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('form button[data-slot="button"]')
        ->assertSee('Transfer details')
        ->keys('[aria-label$="account menu"]', 'Enter')
        ->keys('a[href$="/account/settings"]', 'Enter')
        ->assertSee('Upload photo')
        ->keys('a[href$="/account/settings/security"]', 'Enter')
        ->assertSee('Confirm it’s you')
        ->assertSee('Confirm your identity to manage your password and passkeys.')
        ->fill('password', 'password')
        ->press('Confirm password')
        ->assertPathIs('/account/settings/security')
        ->assertSee('You haven’t added any passkeys yet.');
    $page->script(<<<'JS'
() => {
    window.passkeyCreateCalls = 0;
    Object.defineProperty(navigator.credentials, 'create', {
        configurable: true,
        value: async () => {
            window.passkeyCreateCalls++;
            throw new DOMException('User cancelled the prompt', 'NotAllowedError');
        },
    });
}
JS);

    $page->fill('#passkey-name', 'Work MacBook')
        ->press('Add passkey')
        ->assertSeeIn('[data-slot="toast"]', 'Passkey not added')
        ->assertPathIs('/account/settings/security')
        ->assertDontSee('Confirm it’s you')
        ->assertNoJavascriptErrors();

    expect($page->script('() => window.passkeyCreateCalls'))->toBe(1)
        ->and($page->script('() => document.querySelector("#passkey-name").value'))->toBe('Work MacBook');
});

it('confirms identity before showing passkeys and then removes one without asking again', function (): void {
    $user = User::factory()->create();
    $passkey = $user->passkeys()->create([
        'name' => 'Work MacBook',
        'credential_id' => 'test-credential',
        'credential' => [],
    ]);
    $this->actingAs($user)->withSession([
        'auth.password_confirmed_at' => now()->subHours(4)->timestamp,
    ]);

    $page = visit('/account/settings/security')
        ->assertSee('Confirm it’s you')
        ->assertDontSee('Work MacBook');

    $this->assertModelExists($passkey);

    $page->fill('password', 'password')
        ->press('Confirm password')
        ->assertPathIs('/account/settings/security')
        ->press('[aria-label="Remove Work MacBook"]')
        ->assertSeeIn('[data-slot="toast"]', 'Passkey removed')
        ->assertSee('“Work MacBook” can no longer sign you in.')
        ->assertSee('You haven’t added any passkeys yet.')
        ->assertNoJavascriptErrors();

    $this->assertModelMissing($passkey);
});

it('keeps a cancelled named passkey registration recoverable on mobile', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);

    $page = visit('/account/settings/security')->resize(390, 844)
        ->assertSee('You haven’t added any passkeys yet.');
    $page->script(<<<'JS'
() => {
    window.passkeyCreateCalls = 0;
    Object.defineProperty(navigator.credentials, 'create', {
        configurable: true,
        value: async () => {
            window.passkeyCreateCalls++;
            throw new DOMException('User cancelled the prompt', 'NotAllowedError');
        },
    });
}
JS);

    $page->fill('#passkey-name', 'Work MacBook')
        ->assertEnabled('#passkey-form button')
        ->press('Add passkey')
        ->assertSeeIn('[data-slot="toast"]', 'Passkey not added')
        ->assertSeeIn('[data-slot="toast"]', 'The passkey operation was cancelled.')
        ->assertSee('You haven’t added any passkeys yet.')
        ->assertEnabled('#passkey-form button')
        ->assertNoJavascriptErrors();

    expect($page->script('() => window.passkeyCreateCalls'))->toBe(1);
    $page->wait(0.3)->screenshot(fullPage: false, filename: 'toast-passkey-error');
    $page->press('[data-slot="toast"] button:has-text("Try again")')
        ->assertScript('window.passkeyCreateCalls', 2)
        ->assertSeeIn('[data-slot="toast"]', 'The passkey operation was cancelled.')
        ->assertCount('[data-slot="toast"]', 1)
        ->assertNoJavascriptErrors();
    expect($page->script('() => document.querySelector("#passkey-name").value'))->toBe('Work MacBook');
    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    expect($user->passkeys()->count())->toBe(0);
});

it('explains unsupported passkeys and leaves password sign in available on mobile', function (): void {
    $page = visit('/register')->resize(390, 844);
    $page->script(<<<'JS'
() => Object.defineProperty(window, 'PublicKeyCredential', {
    configurable: true,
    value: undefined,
})
JS);

    $page->click('Sign in')
        ->assertSee('Passkeys aren’t supported in this browser. You can use your password.')
        ->assertDisabled('button:has-text("Sign in with passkey")')
        ->assertEnabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();

    expect($page->script('() => document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('offers a working reload link when a passkey sign in session expires', function (): void {
    $page = visit('/login');
    $page->script(<<<'JS_WRAP'
    () => {
        const fetch = window.fetch;
        window.fetch = (url, options) => {
            if (new URL(url, location.href).pathname === '/passkeys/login/options') {
                return Promise.resolve(new Response(JSON.stringify({message: 'CSRF token mismatch.'}), {
                    status: 419,
                    headers: {'Content-Type': 'application/json'},
                }));
            }
            return fetch(url, options);
        };
    }
    JS_WRAP);

    $page->press('Sign in with passkey')
        ->assertSee('Your session expired.')
        ->assertEnabled('form button[data-slot="button"]')
        ->assertNoJavascriptErrors();

    expect($page->text('[role="alert"]'))->toContain('Your session expired.');
    expect($page->attribute('a:has-text("Reload this page")', 'href'))->toBe('/login');

    $page->click('Reload this page')
        ->assertSee('Forgot password?')
        ->assertDontSee('Your session expired.')
        ->assertNoJavascriptErrors();
});
